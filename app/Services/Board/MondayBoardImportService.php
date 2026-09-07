<?php

namespace App\Services\Board;

use App\Console\Commands\Board\ImportMondayBoardCommand;
use App\Http\Controllers\Board\BoardViewController;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Database\Seeders\BoardContentSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Parses a monday.com board `.xlsx` export and imports it as a real board
 * (groups, items, subitems and column values), reusing the same table-board
 * engine every hand-built board goes through — see
 * {@see BoardContentSeeder} and
 * {@see BoardViewController::seedDefaultColumns()}
 * for the conventions this mirrors (column `config.options` shape, accent
 * color palette, primary view creation).
 *
 * Used by {@see ImportMondayBoardCommand}, which
 * owns all file/CLI/transaction concerns — this service only knows how to
 * turn a parsed worksheet into rows.
 */
class MondayBoardImportService
{
    /** The grey fill color monday.com's export uses for a table's header row. */
    private const HEADER_FILL_COLOR = 'D6D6D6';

    /** @var array<int, string> */
    private const OPTION_COLOR_PALETTE = [
        '#00c875', '#579bfc', '#a25ddc', '#fdab3d', '#e2445c',
        '#66ccff', '#ff642e', '#7f5347', '#bb3354', '#0086c0', '#9d99b9',
    ];

    /** Familiar monday.com status/priority labels get their usual color instead of a palette-cycled one. */
    private const KNOWN_OPTION_COLORS = [
        'Done' => '#00c875',
        'Working on it' => '#fdab3d',
        'Stuck' => '#e2445c',
        'On hold' => '#797e93',
        'On Track' => '#00c875',
        'Ready for Dev' => '#579bfc',
        'Waiting for deployment' => '#a25ddc',
        'Outlining' => '#9d99b9',
        'Backlog' => '#c4c4c4',
        'Resources' => '#66ccff',
        'In Progress' => '#fdab3d',
        'High' => '#e2445c',
        'Medium' => '#fdab3d',
        'Low' => '#579bfc',
    ];

    /**
     * Reads the whole sheet into an in-memory tree, without touching the database.
     *
     * @return array{
     *     title: string,
     *     groups: array<int, array{
     *         name: string,
     *         items: array<int, array{
     *             name: string, assignee: string, priority: string, status: string,
     *             kanban_status: string, timeline_start: string, timeline_end: string,
     *             working_branch: string, points: string, tools: string, project: string,
     *             goal_completion_date: string, text: string, monday_id: string,
     *             subitems: array<int, array{name: string, owner: string, status: string, date: string, monday_id: string}>,
     *         }>,
     *     }>,
     *     distinct: array<string, array<string, bool>>,
     * }
     */
    public function parse(Worksheet $sheet): array
    {
        $groups = [];
        $group_index = -1;
        $item_index = -1;
        $mode = 'items';

        $distinct = [
            'priority' => [],
            'status' => [],
            'kanban_status' => [],
            'tools' => [],
            'project' => [],
            'subitem_status' => [],
        ];

        $highest_row = $sheet->getHighestRow();

        for ($row = 2; $row <= $highest_row; $row++) {
            $col_a = $this->cell($sheet, 'A', $row);

            if ($this->isHeaderRow($sheet, $row)) {
                $mode = 'items';

                continue;
            }

            if ($col_a === 'Subitems' && $this->cell($sheet, 'B', $row) === 'Name' && $this->cell($sheet, 'C', $row) === 'Owner') {
                $mode = 'subitems';

                continue;
            }

            if ($mode === 'subitems') {
                $subitem_id = $this->cell($sheet, 'F', $row);

                if ($subitem_id !== '' && ctype_digit($subitem_id) && $item_index >= 0) {
                    $status = $this->cell($sheet, 'D', $row);

                    if ($status !== '') {
                        $distinct['subitem_status'][$status] = true;
                    }

                    $groups[$group_index]['items'][$item_index]['subitems'][] = [
                        'name' => $this->cell($sheet, 'B', $row),
                        'owner' => $this->cell($sheet, 'C', $row),
                        'status' => $status,
                        'date' => $this->cell($sheet, 'E', $row),
                        'monday_id' => $subitem_id,
                    ];

                    continue;
                }

                $mode = 'items';
                // Falls through: this row didn't match a subitem, so it's the
                // next real item/group row — re-evaluate it below.
            }

            $item_id = $this->cell($sheet, 'O', $row);

            if ($col_a !== '' && $item_id !== '' && ctype_digit($item_id)) {
                if ($group_index < 0) {
                    continue; // Defensive: an item row appeared before any group title.
                }

                $priority = $this->cell($sheet, 'D', $row);
                $status = $this->cell($sheet, 'E', $row);
                $kanban_status = $this->cell($sheet, 'F', $row);
                $tools = $this->cell($sheet, 'K', $row);
                $project = $this->cell($sheet, 'L', $row);

                if ($priority !== '') {
                    $distinct['priority'][$priority] = true;
                }
                if ($status !== '') {
                    $distinct['status'][$status] = true;
                }
                if ($kanban_status !== '') {
                    $distinct['kanban_status'][$kanban_status] = true;
                }
                if ($project !== '') {
                    $distinct['project'][$project] = true;
                }
                foreach ($this->splitList($tools) as $tool) {
                    $distinct['tools'][$tool] = true;
                }

                $groups[$group_index]['items'][] = [
                    'name' => $col_a,
                    'assignee' => $this->cell($sheet, 'C', $row),
                    'priority' => $priority,
                    'status' => $status,
                    'kanban_status' => $kanban_status,
                    'timeline_start' => $this->cell($sheet, 'G', $row),
                    'timeline_end' => $this->cell($sheet, 'H', $row),
                    'working_branch' => $this->cell($sheet, 'I', $row),
                    'points' => $this->cell($sheet, 'J', $row),
                    'tools' => $tools,
                    'project' => $project,
                    'goal_completion_date' => $this->cell($sheet, 'M', $row),
                    'text' => $this->cell($sheet, 'N', $row),
                    'monday_id' => $item_id,
                    'subitems' => [],
                ];
                $item_index = count($groups[$group_index]['items']) - 1;

                continue;
            }

            if ($col_a !== '' && $item_id === '' && $this->isHeaderRow($sheet, $row + 1)) {
                $groups[] = ['name' => $col_a, 'items' => []];
                $group_index = count($groups) - 1;
                $item_index = -1;

                continue;
            }

            // Blank separator row or an aggregate summary row — nothing to import.
        }

        return [
            'title' => $this->boardTitle($sheet),
            'groups' => $groups,
            'distinct' => $distinct,
        ];
    }

    /**
     * Creates the board's columns, groups, items and subitems from a parsed tree.
     *
     * @param  array<string, mixed>  $parsed  the array returned by {@see parse()}
     * @return array{groups: int, items: int, subitems: int, unmatched_people: array<int, string>}
     */
    public function import(WorkspaceNavigationItem $board, BoardView $view, array $parsed): array
    {
        $users = User::all();

        $item_columns = $this->createItemColumns($board, $view, $parsed['distinct']);
        $subitem_columns = $this->createSubitemColumns($board, $view, $parsed['distinct']);

        $group_count = 0;
        $item_count = 0;
        $subitem_count = 0;
        $unmatched = [];

        foreach ($parsed['groups'] as $group_position => $group_data) {
            $group = $board->groups()->create([
                'board_view_id' => $view->id,
                'name' => $group_data['name'],
                'accent_color' => self::OPTION_COLOR_PALETTE[$group_position % count(self::OPTION_COLOR_PALETTE)],
                'position' => $group_position,
            ]);
            $group_count++;

            foreach ($group_data['items'] as $item_position => $item_data) {
                $item = $board->items()->create([
                    'group_id' => $group->id,
                    'name' => $item_data['name'],
                    'position' => $item_position,
                ]);
                $item_count++;

                $this->storeItemValues($item, $item_columns, $item_data, $users, $unmatched);

                foreach ($item_data['subitems'] as $subitem_position => $subitem_data) {
                    $subitem = $board->items()->create([
                        'group_id' => $group->id,
                        'parent_id' => $item->id,
                        'name' => $subitem_data['name'] !== '' ? $subitem_data['name'] : 'Untitled subitem',
                        'position' => $subitem_position,
                    ]);
                    $subitem_count++;

                    $this->storeSubitemValues($subitem, $subitem_columns, $subitem_data, $users, $unmatched);
                }
            }
        }

        return [
            'groups' => $group_count,
            'items' => $item_count,
            'subitems' => $subitem_count,
            'unmatched_people' => array_values(array_unique($unmatched)),
        ];
    }

    /**
     * @return array<string, BoardColumn>
     */
    private function createItemColumns(WorkspaceNavigationItem $board, BoardView $view, array $distinct): array
    {
        return $this->createColumns($board, $view, BoardColumn::SCOPE_ITEM, [
            'assignee' => ['label' => 'Assignee', 'type' => BoardColumn::TYPE_PEOPLE, 'width' => 160],
            'priority' => ['label' => 'Priority', 'type' => BoardColumn::TYPE_LABEL, 'width' => 130, 'options' => array_keys($distinct['priority'])],
            'status' => ['label' => 'Status', 'type' => BoardColumn::TYPE_STATUS, 'width' => 170, 'options' => array_keys($distinct['status'])],
            'kanban_status' => ['label' => 'Ernesto - Kaban', 'type' => BoardColumn::TYPE_STATUS, 'width' => 160, 'options' => array_keys($distinct['kanban_status'])],
            'timeline' => ['label' => 'Timeline', 'type' => BoardColumn::TYPE_TIMELINE, 'width' => 200],
            'working_branch' => ['label' => 'Working Branch', 'type' => BoardColumn::TYPE_TEXT, 'width' => 140],
            'points' => ['label' => 'Points /3', 'type' => BoardColumn::TYPE_NUMBER, 'width' => 110],
            'tools' => ['label' => 'Tools', 'type' => BoardColumn::TYPE_TAGS, 'width' => 200, 'options' => array_keys($distinct['tools'])],
            'project' => ['label' => 'Project', 'type' => BoardColumn::TYPE_DROPDOWN, 'width' => 180, 'options' => array_keys($distinct['project'])],
            'goal_completion_date' => ['label' => 'Goal Completion Date', 'type' => BoardColumn::TYPE_DATE, 'width' => 170],
            'text' => ['label' => 'Text', 'type' => BoardColumn::TYPE_TEXT, 'width' => 160],
        ]);
    }

    /**
     * @return array<string, BoardColumn>
     */
    private function createSubitemColumns(WorkspaceNavigationItem $board, BoardView $view, array $distinct): array
    {
        return $this->createColumns($board, $view, BoardColumn::SCOPE_SUBITEM, [
            'owner' => ['label' => 'Owner', 'type' => BoardColumn::TYPE_PEOPLE, 'width' => 160],
            'status' => ['label' => 'Status', 'type' => BoardColumn::TYPE_STATUS, 'width' => 160, 'options' => array_keys($distinct['subitem_status'])],
            'date' => ['label' => 'Date', 'type' => BoardColumn::TYPE_DATE, 'width' => 150],
        ]);
    }

    /**
     * @param  array<string, array{label: string, type: string, width: int, options?: array<int, string>}>  $definitions
     * @return array<string, BoardColumn>
     */
    private function createColumns(WorkspaceNavigationItem $board, BoardView $view, string $scope, array $definitions): array
    {
        $columns = [];
        $position = 0;

        foreach ($definitions as $key => $definition) {
            $config = empty($definition['options']) ? null : ['options' => $this->buildOptions($definition['options'])];

            $columns[$key] = $board->columns()->create([
                'board_view_id' => $view->id,
                'scope' => $scope,
                'key' => $key,
                'label' => $definition['label'],
                'type' => $definition['type'],
                'position' => $position,
                'width' => $definition['width'],
                'config' => $config,
            ]);
            $position++;
        }

        return $columns;
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, array{id: string, label: string, color: string, is_active: bool}>
     */
    private function buildOptions(array $labels): array
    {
        $options = [];

        foreach (array_values($labels) as $index => $label) {
            $options[] = [
                'id' => (string) Str::uuid(),
                'label' => $label,
                'color' => self::KNOWN_OPTION_COLORS[$label] ?? self::OPTION_COLOR_PALETTE[$index % count(self::OPTION_COLOR_PALETTE)],
                'is_active' => true,
            ];
        }

        return $options;
    }

    /**
     * @param  array<string, BoardColumn>  $columns
     * @param  array<string, string>  $data
     * @param  Collection<int, User>  $users
     * @param  array<int, string>  $unmatched
     */
    private function storeItemValues(BoardItem $item, array $columns, array $data, Collection $users, array &$unmatched): void
    {
        $values = [];

        if ($data['assignee'] !== '') {
            $resolved = $this->resolvePersonIds($data['assignee'], $users);
            array_push($unmatched, ...$resolved['unmatched']);

            if ($resolved['ids'] !== []) {
                $values[] = ['column_id' => $columns['assignee']->id, 'value' => $resolved['ids']];
            }
        }

        $this->pushSingleOptionValue($values, $columns['priority'], $data['priority']);
        $this->pushSingleOptionValue($values, $columns['status'], $data['status']);
        $this->pushSingleOptionValue($values, $columns['kanban_status'], $data['kanban_status']);

        $timeline = $this->buildTimelineValue($data['timeline_start'], $data['timeline_end']);
        if ($timeline !== null) {
            $values[] = ['column_id' => $columns['timeline']->id, 'value' => $timeline];
        }

        if ($data['working_branch'] !== '') {
            $values[] = ['column_id' => $columns['working_branch']->id, 'value' => $data['working_branch']];
        }

        if ($data['points'] !== '' && is_numeric($data['points'])) {
            $values[] = ['column_id' => $columns['points']->id, 'value' => (float) $data['points']];
        }

        $tool_ids = $this->optionIdsForLabels($columns['tools'], $this->splitList($data['tools']));
        if ($tool_ids !== []) {
            $values[] = ['column_id' => $columns['tools']->id, 'value' => $tool_ids];
        }

        $project_ids = $data['project'] === '' ? [] : $this->optionIdsForLabels($columns['project'], [$data['project']]);
        if ($project_ids !== []) {
            $values[] = ['column_id' => $columns['project']->id, 'value' => $project_ids];
        }

        $goal_date = $this->parseDate($data['goal_completion_date']);
        if ($goal_date !== null) {
            $values[] = ['column_id' => $columns['goal_completion_date']->id, 'value' => $goal_date];
        }

        if ($data['text'] !== '') {
            $values[] = ['column_id' => $columns['text']->id, 'value' => $data['text']];
        }

        if ($values !== []) {
            $item->values()->createMany($values);
        }
    }

    /**
     * @param  array<string, BoardColumn>  $columns
     * @param  array<string, string>  $data
     * @param  Collection<int, User>  $users
     * @param  array<int, string>  $unmatched
     */
    private function storeSubitemValues(BoardItem $subitem, array $columns, array $data, Collection $users, array &$unmatched): void
    {
        $values = [];

        if ($data['owner'] !== '') {
            $resolved = $this->resolvePersonIds($data['owner'], $users);
            array_push($unmatched, ...$resolved['unmatched']);

            if ($resolved['ids'] !== []) {
                $values[] = ['column_id' => $columns['owner']->id, 'value' => $resolved['ids']];
            }
        }

        $this->pushSingleOptionValue($values, $columns['status'], $data['status']);

        $date = $this->parseDate($data['date']);
        if ($date !== null) {
            $values[] = ['column_id' => $columns['date']->id, 'value' => $date];
        }

        if ($values !== []) {
            $subitem->values()->createMany($values);
        }
    }

    /**
     * Appends a single-select (status/label) cell value — the matching option's id — to $values.
     *
     * @param  array<int, array{column_id: int, value: mixed}>  $values
     */
    private function pushSingleOptionValue(array &$values, BoardColumn $column, string $raw): void
    {
        if ($raw === '') {
            return;
        }

        $option_id = $this->findOptionId($column, $raw);

        if ($option_id !== null) {
            $values[] = ['column_id' => $column->id, 'value' => $option_id];
        }
    }

    private function findOptionId(BoardColumn $column, string $label): ?string
    {
        foreach (data_get($column->config, 'options', []) as $option) {
            if ($option['label'] === $label) {
                return $option['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, string>
     */
    private function optionIdsForLabels(BoardColumn $column, array $labels): array
    {
        $ids = [];

        foreach ($labels as $label) {
            $option_id = $this->findOptionId($column, $label);

            if ($option_id !== null) {
                $ids[] = $option_id;
            }
        }

        return $ids;
    }

    /**
     * @return array{start: string, end: string}|null
     */
    private function buildTimelineValue(string $start_raw, string $end_raw): ?array
    {
        $start = $this->parseDate($start_raw);
        $end = $this->parseDate($end_raw);

        if ($start === null || $end === null) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Splits a comma-separated cell (people names, tool tags) into trimmed, non-empty tokens.
     *
     * @return array<int, string>
     */
    private function splitList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $value) => $value !== ''));
    }

    /**
     * Matches each comma-separated name against an existing user — a match requires both the
     * user's first and last name to appear as whole words in the raw name, so "Ernesto McIntosh
     * Afane" matches a user named "Ernesto Afane" even though monday.com's export includes a
     * middle name the app's user record doesn't have.
     *
     * @param  Collection<int, User>  $users
     * @return array{ids: array<int, string>, unmatched: array<int, string>}
     */
    private function resolvePersonIds(string $raw, Collection $users): array
    {
        $ids = [];
        $unmatched = [];

        foreach ($this->splitList($raw) as $name) {
            $haystack = ' '.mb_strtolower($name).' ';

            $user = $users->first(function (User $user) use ($haystack) {
                $first = mb_strtolower($user->first_name);
                $last = mb_strtolower($user->last_name);

                return $first !== '' && $last !== ''
                    && str_contains($haystack, " {$first} ")
                    && str_contains($haystack, " {$last} ");
            });

            if ($user !== null) {
                $ids[] = (string) $user->id;
            } else {
                $unmatched[] = $name;
            }
        }

        return ['ids' => array_values(array_unique($ids)), 'unmatched' => $unmatched];
    }

    private function isHeaderRow(Worksheet $sheet, int $row): bool
    {
        return $this->cell($sheet, 'A', $row) === 'Name'
            && $this->cell($sheet, 'B', $row) === 'Subitems'
            && strtoupper($sheet->getStyle('A'.$row)->getFill()->getStartColor()->getRGB()) === self::HEADER_FILL_COLOR;
    }

    private function boardTitle(Worksheet $sheet): string
    {
        $title = $this->cell($sheet, 'A', 1);

        return $title !== '' ? $title : 'Imported monday.com board';
    }

    private function cell(Worksheet $sheet, string $column, int $row): string
    {
        return trim((string) $sheet->getCell($column.$row)->getFormattedValue());
    }
}
