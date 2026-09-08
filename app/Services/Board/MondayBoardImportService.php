<?php

namespace App\Services\Board;

use App\Console\Commands\Board\ImportMondayBoardCommand;
use App\Console\Commands\Board\ImportMondayBoardTreeCommand;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardView;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Database\Seeders\BoardContentSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Parses a monday.com board `.xlsx` export and imports it as a real board
 * (groups, items, subitems and column values), reusing the same table-board
 * engine every hand-built board goes through — see
 * {@see BoardContentSeeder} for the conventions this mirrors (column
 * `config.options` shape, accent color palette).
 *
 * Every export monday.com produces shares the same skeleton (a title row, an
 * optional description row, one grey-shaded "Name | ... | Item ID" header
 * row per group, and a "Subitems | Name | ... | Item ID" header wherever an
 * item has subitems) but each *board* freely chooses its own column set —
 * "Bugs Queue" has nothing in common with "Sales Resources" beyond that
 * skeleton. Rather than hard-coding one board's columns, {@see parse()}
 * reads whichever columns a header row actually names and
 * {@see inferColumnType()} guesses each one's {@see BoardColumn} type from
 * its label and the values underneath it, so any board's export imports
 * with its own columns intact.
 *
 * Used by {@see ImportMondayBoardCommand} (one file) and
 * {@see ImportMondayBoardTreeCommand} (a whole directory of files), which own
 * all file/CLI/transaction concerns — this service only knows how to turn a
 * parsed worksheet into rows.
 */
class MondayBoardImportService
{
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
     * Header labels (case-insensitive) that mean "this column names a staff member" — matched
     * against {@see resolvePersonIds()} rather than stored as plain text. Deliberately narrow:
     * a client-facing name field like "Contacts" or "Requestor name" stays text instead, since
     * those people usually aren't app users and coercing them into this column would silently
     * drop the name from the import.
     *
     * @var array<int, string>
     */
    private const PEOPLE_LABELS = [
        'owner', 'owners', 'assignee', 'assignees', 'person', 'people', 'reporter', 'developer',
        'interviewer', 'designer', 'epic owner',
    ];

    /** Import the "updates" sheet's comment threads without any credential handling. */
    public const UPDATES_MODE_SKIP = 'skip';

    /** Import every comment, replacing lines that look like a credential with a placeholder. */
    public const UPDATES_MODE_REDACT = 'redact';

    /** Import every comment exactly as monday.com exported it, credentials included. */
    public const UPDATES_MODE_RAW = 'raw';

    /** Import every comment thread except ones containing a line that looks like a credential. */
    public const UPDATES_MODE_EXCLUDE = 'exclude';

    /**
     * Matches a line that looks like a credential (`PW: ...`, `Password: ...`, `API Key: ...`,
     * `Login: ...`, `Token: ...`, ...) — monday.com "Resources" updates in the wild store AWS/
     * GitHub/Forge/MailGun logins this way, one label per line.
     */
    private const SECRET_LINE_PATTERN = '/^\s*(u|p|pw|pwd|pass|password|passwd|user(name)?|login|secret|token|api[\s_-]?key|apikey|credential)\s*[:=]/i';

    /**
     * Reads the whole sheet into an in-memory tree, without touching the database. Column
     * layout is discovered from whatever the sheet's own header rows name — see the class
     * docblock — so this works the same whether the sheet is a roadmap, a bug queue, or a
     * client directory.
     *
     * @return array{
     *     title: string,
     *     description: string|null,
     *     groups: array<int, array{
     *         name: string,
     *         items: array<int, array{
     *             name: string, monday_id: string, data: array<string, string>,
     *             subitems: array<int, array{name: string, monday_id: string, data: array<string, string>}>,
     *         }>,
     *     }>,
     *     item_columns: array<int, array{key: string, label: string, type: string, options: array<int, string>, source: array<int, string>}>,
     *     subitem_columns: array<int, array{key: string, label: string, type: string, options: array<int, string>, source: array<int, string>}>,
     * }
     */
    public function parse(Worksheet $sheet): array
    {
        $groups = [];
        $group_index = -1;
        $item_index = -1;
        $mode = 'items';

        /** @var array{columns: array<int, array{letter: string, label: string, key: string}>, id_col: string}|null */
        $item_header = null;
        /** @var array{columns: array<int, array{letter: string, label: string, key: string}>, id_col: string}|null */
        $subitem_header = null;

        /** @var array<string, array<int, string>> */
        $item_values = [];
        /** @var array<string, array<int, string>> */
        $subitem_values = [];

        $highest_row = $sheet->getHighestRow();

        for ($row = 2; $row <= $highest_row; $row++) {
            $col_a = $this->cell($sheet, 'A', $row);

            if ($this->isItemHeaderRow($sheet, $row)) {
                $item_header = $this->readHeader($sheet, $row, 'A', []);
                $mode = 'items';

                continue;
            }

            if ($this->isSubitemHeaderRow($sheet, $row)) {
                $subitem_header = $this->readHeader($sheet, $row, 'B', ['A']);
                $mode = 'subitems';

                continue;
            }

            if ($mode === 'subitems' && $subitem_header !== null) {
                $subitem_id = $this->cell($sheet, $subitem_header['id_col'], $row);

                if ($subitem_id !== '' && ctype_digit($subitem_id) && $item_index >= 0) {
                    $data = $this->readRowData($sheet, $row, $subitem_header['columns']);
                    $this->collectValues($subitem_values, $data);

                    $name = $this->cell($sheet, 'B', $row);

                    $groups[$group_index]['items'][$item_index]['subitems'][] = [
                        'name' => $name !== '' ? $name : 'Untitled subitem',
                        'monday_id' => $subitem_id,
                        'data' => $data,
                    ];

                    continue;
                }

                $mode = 'items';
                // Falls through: this row didn't match a subitem, so it's the
                // next real item/group row — re-evaluate it below.
            }

            if ($item_header !== null) {
                $item_id = $this->cell($sheet, $item_header['id_col'], $row);

                if ($col_a !== '' && $item_id !== '' && ctype_digit($item_id)) {
                    if ($group_index < 0) {
                        continue; // Defensive: an item row appeared before any group title.
                    }

                    $data = $this->readRowData($sheet, $row, $item_header['columns']);
                    $this->collectValues($item_values, $data);

                    $groups[$group_index]['items'][] = [
                        'name' => $col_a,
                        'monday_id' => $item_id,
                        'data' => $data,
                        'subitems' => [],
                    ];
                    $item_index = count($groups[$group_index]['items']) - 1;

                    continue;
                }
            }

            if ($col_a !== '' && $this->isItemHeaderRow($sheet, $row + 1)) {
                $groups[] = ['name' => $col_a, 'items' => []];
                $group_index = count($groups) - 1;
                $item_index = -1;

                continue;
            }

            // Blank separator row, the board's description row, or an aggregate summary row.
        }

        return [
            'title' => $this->boardTitle($sheet),
            'description' => $this->boardDescription($sheet),
            'groups' => $groups,
            'item_columns' => $this->inferColumns($item_header['columns'] ?? [], $item_values),
            'subitem_columns' => $this->inferColumns($subitem_header['columns'] ?? [], $subitem_values),
        ];
    }

    /**
     * Creates the board's columns, groups, items and subitems from a parsed tree.
     *
     * @param  array<string, mixed>  $parsed  the array returned by {@see parse()}
     * @return array{groups: int, items: int, subitems: int, unmatched_people: array<int, string>, item_ids_by_monday_id: array<string, int>}
     */
    public function import(WorkspaceNavigationItem $board, BoardView $view, array $parsed): array
    {
        $users = User::all();

        $item_columns = $this->createColumnsFromDefinitions($board, $view, BoardColumn::SCOPE_ITEM, $parsed['item_columns']);
        $subitem_columns = $this->createColumnsFromDefinitions($board, $view, BoardColumn::SCOPE_SUBITEM, $parsed['subitem_columns']);

        $group_count = 0;
        $item_count = 0;
        $subitem_count = 0;
        $unmatched = [];
        $item_ids_by_monday_id = [];

        foreach ($parsed['groups'] as $group_position => $group_data) {
            $group = $board->groups()->create([
                'board_view_id' => $view->id,
                'name' => $this->truncateColumn($group_data['name']),
                'accent_color' => self::OPTION_COLOR_PALETTE[$group_position % count(self::OPTION_COLOR_PALETTE)],
                'position' => $group_position,
            ]);
            $group_count++;

            foreach ($group_data['items'] as $item_position => $item_data) {
                [$item_name, $item_overflow] = $this->splitOverflowingName($item_data['name']);

                $item = $board->items()->create([
                    'group_id' => $group->id,
                    'name' => $item_name,
                    'description' => $item_overflow,
                    'position' => $item_position,
                ]);
                $item_count++;
                $item_ids_by_monday_id[$item_data['monday_id']] = $item->id;

                $this->applyColumnValues($item, $parsed['item_columns'], $item_columns, $item_data['data'], $users, $unmatched);

                foreach ($item_data['subitems'] as $subitem_position => $subitem_data) {
                    [$subitem_name, $subitem_overflow] = $this->splitOverflowingName($subitem_data['name']);

                    $subitem = $board->items()->create([
                        'group_id' => $group->id,
                        'parent_id' => $item->id,
                        'name' => $subitem_name,
                        'description' => $subitem_overflow,
                        'position' => $subitem_position,
                    ]);
                    $subitem_count++;
                    $item_ids_by_monday_id[$subitem_data['monday_id']] = $subitem->id;

                    $this->applyColumnValues($subitem, $parsed['subitem_columns'], $subitem_columns, $subitem_data['data'], $users, $unmatched);
                }
            }
        }

        return [
            'groups' => $group_count,
            'items' => $item_count,
            'subitems' => $subitem_count,
            'unmatched_people' => array_values(array_unique($unmatched)),
            'item_ids_by_monday_id' => $item_ids_by_monday_id,
        ];
    }

    /**
     * Reads the "updates" sheet into a flat, chronologically-ordered row list, without
     * touching the database.
     *
     * @return array<int, array{monday_item_id: string, author: string, created_at: string, body: string, post_id: string, parent_post_id: string}>
     */
    public function parseUpdates(Worksheet $sheet): array
    {
        $rows = [];
        $highest_row = $sheet->getHighestRow();

        for ($row = 1; $row <= $highest_row; $row++) {
            $monday_item_id = $this->cell($sheet, 'A', $row);
            $body = $this->cell($sheet, 'G', $row);
            $post_id = $this->cell($sheet, 'J', $row);

            // monday.com's own ids are always numeric — this also naturally skips the
            // title row and the "Item ID | Item Name | ..." header row underneath it,
            // without depending on either being at a fixed row number.
            if (! ctype_digit($monday_item_id) || $body === '' || ! ctype_digit($post_id)) {
                continue;
            }

            $rows[] = [
                'monday_item_id' => $monday_item_id,
                'author' => $this->cell($sheet, 'E', $row),
                'created_at' => $this->cell($sheet, 'F', $row),
                'body' => $body,
                'post_id' => $post_id,
                'parent_post_id' => $this->cell($sheet, 'K', $row),
            ];
        }

        return $rows;
    }

    /**
     * Creates the item detail drawer's comment threads (updates + replies) from
     * {@see parseUpdates()}'s rows, matching each row's monday.com item id against the map
     * {@see import()} returned. Threads more than one level deep collapse onto their
     * top-level comment, mirroring {@see BoardItemComment}'s own one-level-of-
     * nesting rule.
     *
     * @param  array<string, int>  $item_ids_by_monday_id
     * @param  array<int, array{monday_item_id: string, author: string, created_at: string, body: string, post_id: string, parent_post_id: string}>  $rows
     * @return array{comments: int, replies: int, skipped_no_item: int, skipped_secret: int, unmatched_authors: array<int, string>}
     */
    public function importUpdates(array $item_ids_by_monday_id, array $rows, string $mode): array
    {
        $users = User::all();
        $comment_count = 0;
        $reply_count = 0;
        $skipped_no_item = 0;
        $skipped_secret = 0;
        $unmatched = [];

        /** @var array<string, BoardItemComment> $comments_by_post_id */
        $comments_by_post_id = [];

        foreach ($rows as $row) {
            if ($row['parent_post_id'] !== '') {
                continue; // Replies are created in the second pass, once every parent exists.
            }

            $item_id = $item_ids_by_monday_id[$row['monday_item_id']] ?? null;
            if ($item_id === null) {
                $skipped_no_item++;

                continue;
            }

            $body = $this->prepareCommentBody($row['body'], $mode);
            if ($body === null) {
                $skipped_secret++;

                continue;
            }

            $comment = $this->createComment($item_id, null, $row, $body, $users, $unmatched);
            $comments_by_post_id[$row['post_id']] = $comment;
            $comment_count++;
        }

        foreach ($rows as $row) {
            if ($row['parent_post_id'] === '') {
                continue;
            }

            $parent = $comments_by_post_id[$row['parent_post_id']] ?? null;
            if ($parent === null) {
                $skipped_no_item++;

                continue;
            }

            $body = $this->prepareCommentBody($row['body'], $mode);
            if ($body === null) {
                $skipped_secret++;

                continue;
            }

            $this->createComment($parent->item_id, $parent->id, $row, $body, $users, $unmatched);
            $reply_count++;
        }

        return [
            'comments' => $comment_count,
            'replies' => $reply_count,
            'skipped_no_item' => $skipped_no_item,
            'skipped_secret' => $skipped_secret,
            'unmatched_authors' => array_values(array_unique($unmatched)),
        ];
    }

    /**
     * @param  array{monday_item_id: string, author: string, created_at: string, body: string, post_id: string, parent_post_id: string}  $row
     * @param  Collection<int, User>  $users
     * @param  array<int, string>  $unmatched
     */
    private function createComment(int $item_id, ?int $parent_id, array $row, string $body, Collection $users, array &$unmatched): BoardItemComment
    {
        $resolved = $this->resolvePersonIds($row['author'], $users);
        array_push($unmatched, ...$resolved['unmatched']);

        $comment = BoardItemComment::create([
            'item_id' => $item_id,
            'parent_id' => $parent_id,
            'user_id' => $resolved['ids'][0] ?? null,
            'body' => $body,
        ]);

        $created_at = $this->parseDateTime($row['created_at']) ?? $comment->created_at;
        $comment->forceFill(['created_at' => $created_at, 'updated_at' => $created_at])->save();

        return $comment;
    }

    /**
     * Applies the chosen {@see UPDATES_MODE_*} handling for lines that look like a credential.
     * Returns null when the whole comment should be dropped (exclude mode, secret found).
     */
    private function prepareCommentBody(string $body, string $mode): ?string
    {
        if ($mode === self::UPDATES_MODE_RAW) {
            return $body;
        }

        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [$body];
        $has_secret = false;

        $redacted_lines = array_map(function (string $line) use (&$has_secret) {
            if (preg_match(self::SECRET_LINE_PATTERN, $line) !== 1) {
                return $line;
            }

            $has_secret = true;

            return '[REDACTED]';
        }, $lines);

        if (! $has_secret) {
            return $body;
        }

        if ($mode === self::UPDATES_MODE_EXCLUDE) {
            return null;
        }

        return implode("\n", $redacted_lines);
    }

    private function parseDateTime(string $raw): ?Carbon
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            // monday.com's own export format, e.g. "08/July/2021 05:23:52 PM".
            return Carbon::createFromFormat('d/F/Y h:i:s A', $raw);
        } catch (Throwable) {
            try {
                return Carbon::parse($raw);
            } catch (Throwable) {
                return null;
            }
        }
    }

    /**
     * True when `$row` is a board's own "Name | ... | Item ID (auto generated)" header row —
     * the literal text monday.com's exporter always uses, regardless of which columns sit
     * between those two.
     */
    private function isItemHeaderRow(Worksheet $sheet, int $row): bool
    {
        if ($this->cell($sheet, 'A', $row) !== 'Name') {
            return false;
        }

        return $this->isItemIdLabel($this->lastNonEmptyCellValue($sheet, $row));
    }

    /** True when `$row` is a "Subitems | Name | ... | Item ID (auto generated)" header row. */
    private function isSubitemHeaderRow(Worksheet $sheet, int $row): bool
    {
        if ($this->cell($sheet, 'A', $row) !== 'Subitems' || $this->cell($sheet, 'B', $row) !== 'Name') {
            return false;
        }

        return $this->isItemIdLabel($this->lastNonEmptyCellValue($sheet, $row));
    }

    private function isItemIdLabel(string $label): bool
    {
        return str_starts_with(mb_strtolower($label), 'item id');
    }

    private function lastNonEmptyCellValue(Worksheet $sheet, int $row): string
    {
        $column = $this->lastNonEmptyColumn($sheet, $row);

        return $column === null ? '' : $this->cell($sheet, $column, $row);
    }

    private function lastNonEmptyColumn(Worksheet $sheet, int $row): ?string
    {
        $highest_index = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($i = $highest_index; $i >= 1; $i--) {
            $letter = Coordinate::stringFromColumnIndex($i);

            if ($this->cell($sheet, $letter, $row) !== '') {
                return $letter;
            }
        }

        return null;
    }

    /**
     * Reads a header row into an ordered column list, keyed by a slug of its own label — e.g.
     * a "Due Date" header becomes `['letter' => 'D', 'label' => 'Due Date', 'key' => 'due_date']`.
     * `$name_col` (the item/subitem name column) and `$marker_cols` (the literal "Subitems"
     * label that only marks a subitems header as such) are excluded, as is any column actually
     * labeled "Subitems" — monday.com's own auto-generated rollup of an item's subitem names,
     * redundant with the real subitems this import already creates.
     *
     * @param  array<int, string>  $marker_cols
     * @return array{columns: array<int, array{letter: string, label: string, key: string}>, id_col: string}
     */
    private function readHeader(Worksheet $sheet, int $row, string $name_col, array $marker_cols): array
    {
        $id_col = $this->lastNonEmptyColumn($sheet, $row) ?? $name_col;
        $highest_index = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        $columns = [];

        for ($i = 1; $i <= $highest_index; $i++) {
            $letter = Coordinate::stringFromColumnIndex($i);

            if ($letter === $name_col || $letter === $id_col || in_array($letter, $marker_cols, true)) {
                continue;
            }

            $label = $this->cell($sheet, $letter, $row);

            if ($label === '' || mb_strtolower(trim($label)) === 'subitems') {
                continue;
            }

            $columns[] = ['letter' => $letter, 'label' => $label, 'key' => $this->columnKey($label, $columns)];
        }

        return ['columns' => $columns, 'id_col' => $id_col];
    }

    /**
     * A stable, unique-within-this-header machine key for a column label, e.g. "Due Date" ->
     * `due_date`. Collisions (a board with two columns literally both named "Task") get a
     * numeric suffix.
     *
     * @param  array<int, array{key: string}>  $existing_columns
     */
    private function columnKey(string $label, array $existing_columns): string
    {
        $base = (string) Str::of($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');

        if ($base === '') {
            $base = 'field';
        }

        $existing_keys = array_column($existing_columns, 'key');
        $key = $base;
        $suffix = 2;

        while (in_array($key, $existing_keys, true)) {
            $key = $base.'_'.$suffix;
            $suffix++;
        }

        return $key;
    }

    /**
     * @param  array<int, array{letter: string, label: string, key: string}>  $columns
     * @return array<string, string>
     */
    private function readRowData(Worksheet $sheet, int $row, array $columns): array
    {
        $data = [];

        foreach ($columns as $column) {
            $data[$column['key']] = $this->cell($sheet, $column['letter'], $row);
        }

        return $data;
    }

    /**
     * @param  array<string, array<int, string>>  $collected
     * @param  array<string, string>  $data
     */
    private function collectValues(array &$collected, array $data): void
    {
        foreach ($data as $key => $value) {
            if ($value !== '') {
                $collected[$key][] = $value;
            }
        }
    }

    /**
     * Turns a header's raw column list into {@see BoardColumn} definitions: a "Timeline -
     * Start" / "Timeline - End" (or "Date - Start" / "Date - End", ...) pair collapses into one
     * {@see BoardColumn::TYPE_TIMELINE} column, and every other column gets its type guessed by
     * {@see inferColumnType()} from its label and the values collected under it.
     *
     * @param  array<int, array{letter: string, label: string, key: string}>  $header_columns
     * @param  array<string, array<int, string>>  $collected_values
     * @return array<int, array{key: string, label: string, type: string, options: array<int, string>, source: array<int, string>}>
     */
    private function inferColumns(array $header_columns, array $collected_values): array
    {
        $consumed = [];
        $definitions = [];

        foreach ($header_columns as $column) {
            if (preg_match('/^(.*?)\s*-\s*start$/i', $column['label'], $matches) !== 1) {
                continue;
            }

            $prefix = trim($matches[1]);
            $end_column = $this->findColumnByLabel($header_columns, ($prefix !== '' ? $prefix.' ' : '').'- End');

            if ($end_column === null) {
                continue;
            }

            $definitions[] = [
                'key' => $this->columnKey($prefix !== '' ? $prefix : 'Timeline', $definitions),
                'label' => $prefix !== '' ? $prefix : 'Timeline',
                'type' => BoardColumn::TYPE_TIMELINE,
                'options' => [],
                'source' => [$column['key'], $end_column['key']],
            ];
            $consumed[$column['key']] = true;
            $consumed[$end_column['key']] = true;
        }

        foreach ($header_columns as $column) {
            if (isset($consumed[$column['key']])) {
                continue;
            }

            [$type, $options] = $this->inferColumnType($column['label'], $collected_values[$column['key']] ?? []);

            $definitions[] = [
                'key' => $column['key'],
                'label' => $column['label'],
                'type' => $type,
                'options' => $options,
                'source' => [$column['key']],
            ];
        }

        return $definitions;
    }

    /**
     * @param  array<int, array{letter: string, label: string, key: string}>  $header_columns
     * @return array{letter: string, label: string, key: string}|null
     */
    private function findColumnByLabel(array $header_columns, string $label): ?array
    {
        foreach ($header_columns as $column) {
            if (strcasecmp(trim($column['label']), $label) === 0) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Guesses a {@see BoardColumn} type from a column's label and its non-empty raw values —
     * see the class docblock. Falls back to {@see BoardColumn::TYPE_TEXT}/`TYPE_LONG_TEXT`
     * whenever the guess is uncertain, so an unrecognized column still imports every value
     * verbatim instead of losing data to a bad type match.
     *
     * @param  array<int, string>  $raw_values
     * @return array{0: string, 1: array<int, string>}
     */
    private function inferColumnType(string $label, array $raw_values): array
    {
        $values = array_values(array_filter($raw_values, fn (string $value) => $value !== ''));
        $label_lower = mb_strtolower(trim($label));

        if ($values === []) {
            return [BoardColumn::TYPE_TEXT, []];
        }

        if (str_contains($label_lower, 'checkbox')) {
            return [BoardColumn::TYPE_CHECKBOX, []];
        }

        if ($this->isAllNumeric($values)) {
            return [BoardColumn::TYPE_NUMBER, []];
        }

        if (str_contains($label_lower, 'date') && ! str_contains($label_lower, 'update') && $this->isMostlyDates($values)) {
            return [BoardColumn::TYPE_DATE, []];
        }

        if (in_array($label_lower, self::PEOPLE_LABELS, true)) {
            return [BoardColumn::TYPE_PEOPLE, []];
        }

        if (str_contains($label_lower, 'priority')) {
            return [BoardColumn::TYPE_LABEL, array_values(array_unique($values))];
        }

        if (str_contains($label_lower, 'status')) {
            return [BoardColumn::TYPE_STATUS, array_values(array_unique($values))];
        }

        if ($this->isMostlyCommaSeparated($values)) {
            $tokens = [];
            foreach ($values as $value) {
                foreach ($this->splitList($value) as $token) {
                    $tokens[$token] = true;
                }
            }

            if (count($tokens) <= 60) {
                return [BoardColumn::TYPE_TAGS, array_keys($tokens)];
            }
        } else {
            $distinct = array_values(array_unique($values));

            if (count($distinct) <= 12 && count($values) > count($distinct) && $this->maxLength($distinct) <= 40) {
                return [BoardColumn::TYPE_STATUS, $distinct];
            }
        }

        if ($this->maxLength($values) > 150 || $this->averageLength($values) > 80) {
            return [BoardColumn::TYPE_LONG_TEXT, []];
        }

        return [BoardColumn::TYPE_TEXT, []];
    }

    /**
     * @param  array<int, string>  $values
     */
    private function isAllNumeric(array $values): bool
    {
        foreach ($values as $value) {
            if (! is_numeric($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function isMostlyDates(array $values): bool
    {
        $parseable = 0;

        foreach ($values as $value) {
            if ($this->parseDate($value) !== null) {
                $parseable++;
            }
        }

        return ($parseable / count($values)) >= 0.9;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function isMostlyCommaSeparated(array $values): bool
    {
        $with_comma = 0;

        foreach ($values as $value) {
            if (str_contains($value, ',')) {
                $with_comma++;
            }
        }

        return ($with_comma / count($values)) >= 0.3;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function maxLength(array $values): int
    {
        return array_reduce($values, fn (int $max, string $value) => max($max, mb_strlen($value)), 0);
    }

    /**
     * @param  array<int, string>  $values
     */
    private function averageLength(array $values): float
    {
        return array_sum(array_map('mb_strlen', $values)) / count($values);
    }

    /**
     * @param  array<int, array{key: string, label: string, type: string, options: array<int, string>, source: array<int, string>}>  $definitions
     * @return array<string, BoardColumn>
     */
    private function createColumnsFromDefinitions(WorkspaceNavigationItem $board, BoardView $view, string $scope, array $definitions): array
    {
        $columns = [];
        $position = 0;

        foreach ($definitions as $definition) {
            $config = empty($definition['options']) ? null : ['options' => $this->buildOptions($definition['options'])];

            $columns[$definition['key']] = $board->columns()->create([
                'board_view_id' => $view->id,
                'scope' => $scope,
                'key' => $this->truncateColumn($definition['key']),
                'label' => $this->truncateColumn($definition['label']),
                'type' => $definition['type'],
                'position' => $position,
                'width' => $this->widthForType($definition['type']),
                'config' => $config,
            ]);
            $position++;
        }

        return $columns;
    }

    private function widthForType(string $type): int
    {
        return match ($type) {
            BoardColumn::TYPE_PEOPLE => 160,
            BoardColumn::TYPE_STATUS => 170,
            BoardColumn::TYPE_LABEL => 130,
            BoardColumn::TYPE_TIMELINE => 200,
            BoardColumn::TYPE_NUMBER => 110,
            BoardColumn::TYPE_TAGS => 200,
            BoardColumn::TYPE_DROPDOWN => 180,
            BoardColumn::TYPE_DATE => 150,
            BoardColumn::TYPE_CHECKBOX => 90,
            BoardColumn::TYPE_LONG_TEXT => 240,
            default => 160,
        };
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
     * Shapes and pushes one item/subitem's cell values according to each column's inferred
     * type — the write-side counterpart of {@see inferColumnType()}.
     *
     * @param  array<int, array{key: string, label: string, type: string, options: array<int, string>, source: array<int, string>}>  $definitions
     * @param  array<string, BoardColumn>  $columns
     * @param  array<string, string>  $data
     * @param  Collection<int, User>  $users
     * @param  array<int, string>  $unmatched
     */
    private function applyColumnValues(BoardItem $item, array $definitions, array $columns, array $data, Collection $users, array &$unmatched): void
    {
        $values = [];

        foreach ($definitions as $definition) {
            $column = $columns[$definition['key']];
            $source = $definition['source'];
            $raw = $data[$source[0]] ?? '';

            switch ($definition['type']) {
                case BoardColumn::TYPE_TIMELINE:
                    $timeline = $this->buildTimelineValue($raw, $data[$source[1]] ?? '');
                    if ($timeline !== null) {
                        $values[] = ['column_id' => $column->id, 'value' => $timeline];
                    }
                    break;

                case BoardColumn::TYPE_PEOPLE:
                    if ($raw !== '') {
                        $resolved = $this->resolvePersonIds($raw, $users);
                        array_push($unmatched, ...$resolved['unmatched']);

                        if ($resolved['ids'] !== []) {
                            $values[] = ['column_id' => $column->id, 'value' => $resolved['ids']];
                        }
                    }
                    break;

                case BoardColumn::TYPE_DATE:
                    $date = $this->parseDate($raw);
                    if ($date !== null) {
                        $values[] = ['column_id' => $column->id, 'value' => $date];
                    }
                    break;

                case BoardColumn::TYPE_NUMBER:
                    if ($raw !== '' && is_numeric($raw)) {
                        $values[] = ['column_id' => $column->id, 'value' => (float) $raw];
                    }
                    break;

                case BoardColumn::TYPE_CHECKBOX:
                    if ($raw !== '') {
                        $values[] = ['column_id' => $column->id, 'value' => true];
                    }
                    break;

                case BoardColumn::TYPE_STATUS:
                case BoardColumn::TYPE_LABEL:
                    $this->pushSingleOptionValue($values, $column, $raw);
                    break;

                case BoardColumn::TYPE_TAGS:
                    $ids = $raw === '' ? [] : $this->optionIdsForLabels($column, $this->splitList($raw));
                    if ($ids !== []) {
                        $values[] = ['column_id' => $column->id, 'value' => $ids];
                    }
                    break;

                default:
                    if ($raw !== '') {
                        $values[] = ['column_id' => $column->id, 'value' => $raw];
                    }
                    break;
            }
        }

        if ($values !== []) {
            $item->values()->createMany($values);
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
     * Splits a comma-separated cell (people names, tag tokens) into trimmed, non-empty tokens.
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

    private function boardTitle(Worksheet $sheet): string
    {
        $title = $this->cell($sheet, 'A', 1);

        return $title !== '' ? $title : 'Imported monday.com board';
    }

    /**
     * Row 2's text, when it's the board's free-text description rather than its first group's
     * name — monday.com's export puts a group title directly under the board title with no
     * description in between whenever the board has no description of its own, so this only
     * counts row 2 as a description when it ISN'T immediately followed by a header row.
     */
    private function boardDescription(Worksheet $sheet): ?string
    {
        $description = $this->cell($sheet, 'A', 2);

        if ($description === '' || $this->isItemHeaderRow($sheet, 3)) {
            return null;
        }

        return $description;
    }

    private function cell(Worksheet $sheet, string $column, int $row): string
    {
        return trim((string) $sheet->getCell($column.$row)->getFormattedValue());
    }

    /**
     * `board_items.name` is a `varchar(255)` column, but a handful of real exports have a
     * "Name" cell far longer than that (someone pasted a whole checklist into one item). Rather
     * than let that row fail the whole import or silently lose text, the overflow moves into
     * the item's `description` field and the visible name gets truncated.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitOverflowingName(string $name): array
    {
        if (mb_strlen($name) <= 255) {
            return [$name, null];
        }

        return [mb_substr($name, 0, 252).'...', $name];
    }

    /** Defensive truncation for `varchar(255)` columns (group names, labels) with nowhere to keep an overflow. */
    private function truncateColumn(string $value, int $limit = 255): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }
}
