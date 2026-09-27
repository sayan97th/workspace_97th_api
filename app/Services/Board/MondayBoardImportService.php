<?php

namespace App\Services\Board;

use App\Concerns\InfersBoardColumnTypes;
use App\Concerns\ReadsSpreadsheetSheets;
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
 * reads whichever columns the header rows actually name (the union of every
 * header, since a subitem header only lists the columns that item's subitems
 * use) and {@see InfersBoardColumnTypes::inferColumnType()} guesses each
 * one's {@see BoardColumn} type from its label and the values underneath it,
 * so any board's export imports with its own columns intact.
 * {@see ImportedCellValueCaster} then writes every cell in the exact shape
 * its column type stores.
 *
 * Used by {@see ImportMondayBoardCommand} (one file) and
 * {@see ImportMondayBoardTreeCommand} (a whole directory of files), which own
 * all file/CLI/transaction concerns — this service only knows how to turn a
 * parsed worksheet into rows.
 */
class MondayBoardImportService
{
    use InfersBoardColumnTypes, ReadsSpreadsheetSheets;

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
     * Reads the whole sheet into an in-memory tree. Column layout is discovered from whatever
     * the sheet's own header rows name — see the class docblock — so this works the same
     * whether the sheet is a roadmap, a bug queue, or a client directory. Only reads the
     * database to match People-looking columns against existing users.
     *
     * Row classification relies on monday.com's own ids: an item row has a name in column A
     * and a long numeric id in its header's "Item ID" column, while a subitem row leaves
     * column A empty and carries its id in the subitem header's own "Item ID" column. Group
     * summary rows (totals under a group or a subitem block) match neither and are skipped.
     *
     * @param  Collection<int, User>|null  $users  users to match People columns against; defaults to every user
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
     *     item_columns: array<int, array{key: string, label: string, type: string, options: array<int, string>, source: array<int, string>, reason: string}>,
     *     subitem_columns: array<int, array{key: string, label: string, type: string, options: array<int, string>, source: array<int, string>, reason: string}>,
     * }
     */
    public function parse(Worksheet $sheet, ?Collection $users = null): array
    {
        $groups = [];
        $group_index = -1;
        $item_index = -1;
        $mode = 'items';

        /** @var array{columns: array<int, array{letter: string, label: string, key: string}>, id_col: string}|null */
        $item_header = null;
        /** @var array{columns: array<int, array{letter: string, label: string, key: string}>, id_col: string}|null */
        $subitem_header = null;

        /** @var array<string, array{label: string, key: string}> $item_header_columns every item column any header named, in first-seen order */
        $item_header_columns = [];
        /** @var array<string, array{label: string, key: string}> $subitem_header_columns */
        $subitem_header_columns = [];

        /** @var array<string, array<int, string>> */
        $item_values = [];
        /** @var array<string, array<int, string>> */
        $subitem_values = [];

        $highest_row = $sheet->getHighestRow();

        for ($row = 2; $row <= $highest_row; $row++) {
            $col_a = $this->cell($sheet, 'A', $row);

            if ($this->isItemHeaderRow($sheet, $row)) {
                $item_header = $this->readHeader($sheet, $row, 'A');
                $this->mergeHeaderColumns($item_header_columns, $item_header['columns']);
                $mode = 'items';

                continue;
            }

            if ($this->isSubitemHeaderRow($sheet, $row)) {
                $subitem_header = $this->readHeader($sheet, $row, 'B');
                $this->mergeHeaderColumns($subitem_header_columns, $subitem_header['columns']);
                $mode = 'subitems';

                continue;
            }

            if ($mode === 'subitems' && $subitem_header !== null) {
                $subitem_id = $this->cell($sheet, $subitem_header['id_col'], $row);

                if ($col_a === '' && $this->isMondayId($subitem_id) && $item_index >= 0) {
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
                // next real item/group row (or a summary row) — re-evaluate it below.
            }

            if ($item_header !== null) {
                $item_id = $this->cell($sheet, $item_header['id_col'], $row);

                if ($col_a !== '' && $this->isMondayId($item_id)) {
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

        $users ??= User::all();

        return [
            'title' => $this->boardTitle($sheet),
            'description' => $this->boardDescription($sheet),
            'groups' => $groups,
            'item_columns' => $this->inferColumns(array_values($item_header_columns), $item_values, $users),
            'subitem_columns' => $this->inferColumns(array_values($subitem_header_columns), $subitem_values, $users),
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
        $caster = new ImportedCellValueCaster($board, User::all());

        $item_columns = $this->createColumnsFromDefinitions($board, $view, BoardColumn::SCOPE_ITEM, $parsed['item_columns']);
        $subitem_columns = $this->createColumnsFromDefinitions($board, $view, BoardColumn::SCOPE_SUBITEM, $parsed['subitem_columns']);

        $group_count = 0;
        $item_count = 0;
        $subitem_count = 0;
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

                $this->applyColumnValues($item, $parsed['item_columns'], $item_columns, $item_data['data'], $caster);

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

                    $this->applyColumnValues($subitem, $parsed['subitem_columns'], $subitem_columns, $subitem_data['data'], $caster);
                }
            }
        }

        return [
            'groups' => $group_count,
            'items' => $item_count,
            'subitems' => $subitem_count,
            'unmatched_people' => $caster->unmatchedPeople(),
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
     * Adds a header's columns to the running union of every header seen so far, keyed by
     * column key, so a column only one group's (or one item's subitems') header names still
     * becomes a real column — monday.com only lists the subitem columns each item's own
     * subitems actually use.
     *
     * @param  array<string, array{label: string, key: string}>  $union
     * @param  array<int, array{letter: string, label: string, key: string}>  $columns
     */
    private function mergeHeaderColumns(array &$union, array $columns): void
    {
        foreach ($columns as $column) {
            $union[$column['key']] ??= ['label' => $column['label'], 'key' => $column['key']];
        }
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
     * Turns the header columns into {@see BoardColumn} definitions: a "Timeline - Start" /
     * "Timeline - End" (or "Date - Start" / "Date - End", ...) pair collapses into one
     * {@see BoardColumn::TYPE_TIMELINE} column, and every other column gets its type guessed by
     * {@see InfersBoardColumnTypes::inferColumnType()} from its label and the values collected
     * under it.
     *
     * @param  array<int, array{label: string, key: string}>  $header_columns
     * @param  array<string, array<int, string>>  $collected_values
     * @param  Collection<int, User>  $users
     * @return array<int, array{key: string, label: string, type: string, options: array<int, string>, source: array<int, string>, reason: string}>
     */
    private function inferColumns(array $header_columns, array $collected_values, Collection $users): array
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
                'reason' => "Built from the \"{$column['label']}\" / \"{$end_column['label']}\" pair",
            ];
            $consumed[$column['key']] = true;
            $consumed[$end_column['key']] = true;
        }

        foreach ($header_columns as $column) {
            if (isset($consumed[$column['key']])) {
                continue;
            }

            [$type, $options, $reason] = $this->inferColumnType($column['label'], $collected_values[$column['key']] ?? [], $users);

            $definitions[] = [
                'key' => $column['key'],
                'label' => $column['label'],
                'type' => $type,
                'options' => $options,
                'source' => [$column['key']],
                'reason' => $reason,
            ];
        }

        return $definitions;
    }

    /**
     * @param  array<int, array{label: string, key: string}>  $header_columns
     * @return array{label: string, key: string}|null
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
     * @param  array<int, array{key: string, label: string, type: string, options: array<int, string>, source: array<int, string>, reason: string}>  $definitions
     * @return array<string, BoardColumn>
     */
    private function createColumnsFromDefinitions(WorkspaceNavigationItem $board, BoardView $view, string $scope, array $definitions): array
    {
        $columns = [];

        foreach (array_values($definitions) as $position => $definition) {
            $columns[$definition['key']] = $board->columns()->create([
                'board_view_id' => $view->id,
                'scope' => $scope,
                'key' => $this->truncateColumn($definition['key']),
                'label' => $this->truncateColumn($definition['label']),
                'type' => $definition['type'],
                'position' => $position,
                'width' => $this->widthForType($definition['type']),
                'config' => $this->initialColumnConfig($definition['type'], $definition['options']),
            ]);
        }

        return $columns;
    }

    /**
     * Casts and writes one item/subitem's cell values — see {@see ImportedCellValueCaster}.
     *
     * @param  array<int, array{key: string, label: string, type: string, options: array<int, string>, source: array<int, string>, reason: string}>  $definitions
     * @param  array<string, BoardColumn>  $columns
     * @param  array<string, string>  $data
     */
    private function applyColumnValues(BoardItem $item, array $definitions, array $columns, array $data, ImportedCellValueCaster $caster): void
    {
        $values = [];

        foreach ($definitions as $definition) {
            $column = $columns[$definition['key']];
            $source = $definition['source'];
            $end_raw = isset($source[1]) ? ($data[$source[1]] ?? '') : null;

            $value = $caster->cast($column, $data[$source[0]] ?? '', $end_raw);

            if ($value !== null) {
                $values[] = ['column_id' => $column->id, 'value' => $value];
            }
        }

        if ($values !== []) {
            $item->values()->createMany($values);
        }
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
