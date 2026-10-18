<?php

namespace App\Services\Board;

use App\Concerns\CombinesSplitImportColumns;
use App\Concerns\InfersBoardColumnTypes;
use App\Concerns\ReadsSpreadsheetSheets;
use App\Jobs\ProcessBoardImportJob;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardImportJob;
use App\Models\BoardItem;
use App\Models\BoardTag;
use App\Models\BoardView;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Backs the board header's "More actions" > "Import items" wizard
 * (`BoardImportController`) — the generic, three-step ("Upload" / "Map
 * columns" / "Handle matches") counterpart of the `board:import-monday*`
 * artisan commands. Reuses those commands' own row/type-inference logic (see
 * {@see ReadsSpreadsheetSheets}, {@see InfersBoardColumnTypes}, both lifted
 * from {@see MondayBoardImportService}) so a raw monday.com board export
 * dropped into the wizard is recognized the same way, but — unlike the CLI
 * importer, which creates a brand-new board with its own groups/subitems —
 * this always imports into a single target table (group) the user picks,
 * flattening every source row into it and skipping subitems, since the
 * wizard's column-mapping UI has no per-group or per-subitem concept.
 *
 * A three-step wizard can't finish the whole import in one request, so the
 * uploaded file is parsed once (`readSpreadsheet`) and cached to disk keyed
 * by a short-lived token (`storeParsedImport`/`loadParsedImport`) — the
 * "Map columns"/"Handle matches" steps and the final `commit()` never need
 * to touch the uploaded file again. That disk cache only bridges those
 * in-request steps though: once `commit()` reads it back, `BoardImportController`
 * copies the payload onto the {@see BoardImportJob} row itself
 * before deleting the cache file, since the queued job that does the actual
 * writing (`ProcessBoardImportJob`) may run on a different machine than
 * whichever one handled this request.
 */
class BoardItemImportService
{
    use CombinesSplitImportColumns, InfersBoardColumnTypes, ReadsSpreadsheetSheets;

    /** How many rows deep to scan for a monday.com-style "Name | ... | Item ID" header before giving up and treating row 1 as a plain header. */
    private const HEADER_SCAN_LIMIT = 60;

    /** Defensive cap — importing more rows than this in one request risks a timeout, not a real-world board size. */
    public const MAX_ROWS = 10000;

    private const STORAGE_DIR = 'board-imports';

    /** A parsed-and-cached upload older than this is pruned the next time anyone starts a new import. */
    private const TOKEN_TTL_HOURS = 6;

    /**
     * Parses the first sheet of an uploaded .csv/.xlsx/.xls file into a flat
     * header + row-value grid, auto-detecting whether it's a raw monday.com
     * board export (title/description/group-title rows, one repeated "Name |
     * ... | Item ID" header per group) or a plain table (row 1 = header).
     * Columns one value was split across (a Timeline's "- Start"/"- End"
     * pair, a checklist's repeated "Task | Status" pairs) come back merged
     * into one column, see {@see CombinesSplitImportColumns}; `combined_from`
     * lists, per merged column index, the source labels it was built from.
     *
     * A monday.com export also brings back its structure: `row_groups` holds
     * the group title each row sat under, and `subitems` the subitem rows
     * listed under each row (keyed by row index), whose columns are
     * `subitem_columns`. A plain table leaves all three empty.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, combined_from: array<int, array<int, string>>, row_groups: array<int, string>, subitem_columns: array<int, array{key: string, label: string}>, subitems: array<int, array<int, array{name: string, data: array<string, string>}>>}
     */
    public function readSpreadsheet(UploadedFile $file): array
    {
        // Deliberately NOT `setReadDataOnly(true)` — a date-formatted cell's
        // number format code lives in its style, and `getFormattedValue()`
        // (what `cell()` below reads) needs that to render "3/1/2022"
        // instead of the raw Excel date serial underneath it.
        $reader = IOFactory::createReader($this->readerTypeFor($file->getClientOriginalExtension()));
        $spreadsheet = $reader->load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(0);

        $header_row = $this->findMondayHeaderRow($sheet);

        $parsed = $header_row !== null
            ? $this->readMondayStyleSheet($sheet, $header_row)
            : [...$this->readFlatSheet($sheet), 'row_groups' => [], 'subitem_columns' => [], 'subitems' => []];

        return [
            ...$this->combineSplitColumns($parsed['headers'], $parsed['rows']),
            'row_groups' => $parsed['row_groups'],
            'subitem_columns' => $parsed['subitem_columns'],
            'subitems' => $parsed['subitems'],
        ];
    }

    /**
     * The groups a monday.com export's rows sat under, in file order, with
     * how many rows each holds. Feeds the "Add items to" picker's "Groups
     * from file" choice. Empty for a plain table.
     *
     * @param  array<int, string>  $row_groups
     * @return array<int, array{name: string, row_count: int}>
     */
    public function summarizeFileGroups(array $row_groups): array
    {
        $counts = [];

        foreach ($row_groups as $name) {
            if ($name !== '') {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        return array_map(fn (string $name, int $count) => ['name' => $name, 'row_count' => $count], array_keys($counts), array_values($counts));
    }

    /**
     * How many subitem rows a monday.com export lists under its items.
     *
     * @param  array<int, array<int, array{name: string, data: array<string, string>}>>  $subitems
     */
    public function countSubitems(array $subitems): int
    {
        return array_sum(array_map('count', $subitems));
    }

    /**
     * One entry per source column: its sample values and a guessed
     * {@see BoardColumn} type — feeds the "Map columns" step's column list
     * and its "create a new column" mapping mode's pre-filled type.
     *
     * `detection_reason` is the human-readable "why" behind
     * `suggested_type` (e.g. "100% of values are dates"), shown under the
     * column in the wizard so a guess can be checked before importing.
     * `compatible_types` lists every creatable type that can hold (nearly)
     * all of the column's values, so the wizard can recommend those and warn
     * when a column is pointed at a type that would drop values.
     * `combined_from` names the source columns a merged column was built from
     * (empty for an ordinary column).
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, array<int, string>>  $combined_from  {@see readSpreadsheet()}'s own `combined_from`
     * @return array<int, array{index: int, label: string, sample_values: array<int, string>, suggested_type: string, detection_reason: string, compatible_types: array<int, string>, combined_from: array<int, string>, filled_count: int}>
     */
    public function buildSourceColumns(array $headers, array $rows, array $combined_from = []): array
    {
        $columns = [];
        $users = User::all();

        foreach ($headers as $index => $label) {
            $non_empty = $this->columnValues($rows, $index);
            [$type, , $reason] = $this->inferColumnType($label, $non_empty, $users);

            $columns[] = [
                'index' => $index,
                'label' => $label,
                'sample_values' => array_map(
                    fn (string $value) => Str::limit(str_replace(["\r\n", "\r", "\n"], ' · ', $value), 120),
                    array_slice(array_values(array_unique($non_empty)), 0, 4),
                ),
                'suggested_type' => $type,
                'detection_reason' => $reason,
                'compatible_types' => $this->compatibleColumnTypes(self::CREATABLE_COLUMN_TYPES, $non_empty, $users),
                'combined_from' => $combined_from[$index] ?? [],
                'filled_count' => count($non_empty),
            ];
        }

        return $columns;
    }

    /**
     * Pre-fills the "Map columns" step so every source column already has a
     * destination and the user never has to hand-pick one: the column
     * literally labeled "Name" (or, failing that, the first column — every
     * monday.com export's own convention) is mapped to the item's built-in
     * name field, any other column whose label case-insensitively matches an
     * existing board column is mapped straight to it, and every remaining
     * column defaults to `"create"` — a brand-new column typed from
     * `buildSourceColumns()`'s own value-based guess (`suggested_type`) —
     * rather than being left unmapped. The user can still override any row
     * to "Don't import" themselves; nothing here is forced.
     *
     * @param  array<int, array{index: int, label: string, sample_values: array<int, string>, suggested_type: string, detection_reason: string}>  $source_columns  {@see buildSourceColumns()}'s output — already carries each column's guessed type
     * @param  Collection<int, BoardColumn>  $existing_columns
     * @return array<int, array{source_index: int, mode: string, target_column_id: int|null, new_label: string|null, new_type: string|null}>
     */
    public function suggestMappings(array $source_columns, Collection $existing_columns): array
    {
        $name_index = 0;
        foreach ($source_columns as $column) {
            if (mb_strtolower(trim($column['label'])) === 'name') {
                $name_index = $column['index'];
                break;
            }
        }

        // A read-only column (Formula, Mirror, Auto-number) can never store an
        // imported value, so a same-named one is never auto-matched.
        $columns_by_label = $existing_columns
            ->reject(fn (BoardColumn $column) => in_array($column->type, BoardColumn::READ_ONLY_TYPES, true))
            ->keyBy(fn (BoardColumn $column) => mb_strtolower(trim($column->label)));
        $creatable_types = $this->creatableColumnTypes();

        $mappings = [];
        foreach ($source_columns as $column) {
            $index = $column['index'];

            if ($index === $name_index) {
                $mappings[] = [
                    'source_index' => $index,
                    'mode' => 'name',
                    'target_column_id' => null,
                    'new_label' => null,
                    'new_type' => null,
                ];

                continue;
            }

            $existing = $columns_by_label->get(mb_strtolower(trim($column['label'])));

            if ($existing !== null) {
                $mappings[] = [
                    'source_index' => $index,
                    'mode' => 'map',
                    'target_column_id' => $existing->id,
                    'new_label' => null,
                    'new_type' => null,
                ];

                continue;
            }

            // monday.com's own Item ID column: the board numbers its items itself.
            if (in_array($column['suggested_type'], BoardColumn::READ_ONLY_TYPES, true)) {
                $mappings[] = [
                    'source_index' => $index,
                    'mode' => 'skip',
                    'target_column_id' => null,
                    'new_label' => null,
                    'new_type' => null,
                ];

                continue;
            }

            $type = in_array($column['suggested_type'], $creatable_types, true) ? $column['suggested_type'] : BoardColumn::TYPE_TEXT;

            $mappings[] = [
                'source_index' => $index,
                'mode' => 'create',
                'target_column_id' => null,
                'new_label' => $this->truncate($column['label']),
                'new_type' => $type,
            ];
        }

        return $mappings;
    }

    /**
     * Caches a parsed upload to disk under a fresh token, so the "Map
     * columns"/"Handle matches" steps (and the eventual `commit()`) never
     * need the original file again, only ever this token.
     *
     * @param  array{headers: array<int, string>, rows: array<int, array<int, string>>, row_groups?: array<int, string>, subitem_columns?: array<int, array{key: string, label: string}>, subitems?: array<int, array<int, array{name: string, data: array<string, string>}>>}  $parsed  {@see readSpreadsheet()}'s output
     */
    public function storeParsedImport(string $file_name, array $parsed): string
    {
        $this->pruneExpiredImports();

        $token = (string) Str::uuid();

        $payload = [
            'file_name' => $file_name,
            'headers' => $parsed['headers'],
            'rows' => $parsed['rows'],
            'row_groups' => $parsed['row_groups'] ?? [],
            'subitem_columns' => $parsed['subitem_columns'] ?? [],
            'subitems' => $parsed['subitems'] ?? [],
            'created_at' => now()->toIso8601String(),
        ];

        Storage::disk('local')->put($this->tokenPath($token), json_encode($payload, JSON_THROW_ON_ERROR));

        return $token;
    }

    /**
     * @return array{file_name: string, headers: array<int, string>, rows: array<int, array<int, string>>}|null null when the token is malformed, expired, or already committed.
     */
    public function loadParsedImport(string $token): ?array
    {
        if (! $this->isValidToken($token)) {
            return null;
        }

        $disk = Storage::disk('local');
        $path = $this->tokenPath($token);

        if (! $disk->exists($path)) {
            return null;
        }

        $payload = json_decode($disk->get($path), true);

        if (! $this->isValidParsedPayload($payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * @phpstan-assert-if-true array{file_name: string, headers: array<int, string>, rows: array<int, array<int, string>>} $payload
     */
    private function isValidParsedPayload(mixed $payload): bool
    {
        if (! is_array($payload) || ! isset($payload['file_name'], $payload['headers'], $payload['rows'])) {
            return false;
        }

        if (! is_string($payload['file_name']) || ! is_array($payload['headers']) || ! is_array($payload['rows'])) {
            return false;
        }

        foreach ($payload['headers'] as $header) {
            if (! is_string($header)) {
                return false;
            }
        }

        foreach ($payload['rows'] as $row) {
            if (! is_array($row)) {
                return false;
            }
            foreach ($row as $cell) {
                if (! is_string($cell)) {
                    return false;
                }
            }
        }

        // The monday.com structure keys are optional, a payload cached before they existed has none.
        foreach (['row_groups', 'subitem_columns', 'subitems'] as $key) {
            if (isset($payload[$key]) && ! is_array($payload[$key])) {
                return false;
            }
        }

        return true;
    }

    public function deleteParsedImport(string $token): void
    {
        if ($this->isValidToken($token)) {
            Storage::disk('local')->delete($this->tokenPath($token));
        }
    }

    /** How many rows are written per database transaction — see `commit()`'s own doc comment. */
    private const CHUNK_SIZE = 100;

    /**
     * Creates (or reuses) the target table and its "create a new column"
     * mappings, then writes every row as a board item — updating/skipping
     * rows that match an existing item when `duplicate_mode` isn't `"add"`.
     *
     * Run by {@see ProcessBoardImportJob} rather than inline on the
     * request that presses "Import Now", precisely so a large file's row
     * count is never bound to one HTTP request's timeout: rows are written
     * {@see self::CHUNK_SIZE} at a time, each chunk in its own short
     * transaction, with `$onChunkProcessed` fired after every one (so the
     * job can update the wizard's progress bar) and `$isCancelled` checked
     * before the next one starts (the wizard's "stop" button) — so the rows
     * already-committed chunks wrote stay written rather than being rolled
     * back, and a big file makes steady, visible progress instead of the
     * caller staring at a stalled request for however long the whole file
     * takes.
     *
     * With `group_mode` "file", rows land in the groups they sat under in a
     * monday.com export (an existing group with the same name is reused), and
     * with `import_subitems` every new item gets the subitem rows listed under
     * it, their columns matched to the tab's subitem columns by name or
     * created like the `board:import-monday` command creates them.
     *
     * @param  array{headers: array<int, string>, rows: array<int, array<int, string>>, row_groups?: array<int, string>, subitem_columns?: array<int, array{key: string, label: string}>, subitems?: array<int, array<int, array{name: string, data: array<string, string>}>>}  $parsed
     * @param  array{
     *     target_group_id: int|null,
     *     new_group_name: string|null,
     *     group_mode?: string,
     *     import_subitems?: bool,
     *     mappings: array<int, array{source_index: int, mode: string, target_column_id: int|null, new_label: string|null, new_type: string|null}>,
     *     duplicate_mode: string,
     *     match_source_index: int|null,
     * }  $options
     * @param  callable(int $processed, int $total): void  $onChunkProcessed
     * @param  callable(): bool  $isCancelled
     * @return array{created: int, updated: int, skipped: int, columns_created: int, groups_created: int, subitems_created: int, group_id: int|null, group_created: bool, processed_rows: int, total_rows: int, cancelled: bool}
     */
    public function commit(
        WorkspaceNavigationItem $board,
        BoardView $view,
        array $parsed,
        array $options,
        callable $onChunkProcessed,
        callable $isCancelled,
    ): array {
        $headers = $parsed['headers'];
        $rows = array_slice($parsed['rows'], 0, self::MAX_ROWS);
        $total = count($rows);

        // Checked once up front too — cancelling an import the instant it's
        // queued shouldn't still leave behind a freshly-created table and
        // columns nobody asked to keep, so this skips the group resolution and
        // `resolveMappings()` entirely rather than creating them first.
        if ($this->checkCancelled($isCancelled)) {
            return $this->cancelledResult($total);
        }

        $row_groups = array_slice($parsed['row_groups'] ?? [], 0, self::MAX_ROWS);
        $uses_file_groups = ($options['group_mode'] ?? self::GROUP_MODE_SINGLE) === self::GROUP_MODE_FILE && $row_groups !== [];
        $subitems_by_row = ($options['import_subitems'] ?? false) ? ($parsed['subitems'] ?? []) : [];

        [$groups_by_name, $default_group, $groups_created] = DB::transaction(fn () => $uses_file_groups
            ? $this->resolveFileGroups($board, $view, $row_groups, $options['new_group_name'] ?? null)
            : [[], ...$this->resolveSingleTargetGroup($board, $view, $options)]);
        [$name_index, $index_to_column, $columns_created] = DB::transaction(
            fn () => $this->resolveMappings($board, $view, $headers, $rows, $options['mappings'])
        );

        $users = User::all();
        [$subitem_definitions, $subitem_columns, $subitem_columns_created] = $subitems_by_row === []
            ? [[], [], 0]
            : DB::transaction(fn () => $this->resolveSubitemColumns($board, $view, $parsed['subitem_columns'] ?? [], $subitems_by_row, $users));
        $columns_created += $subitem_columns_created;

        $duplicate_mode = $options['duplicate_mode'];
        $match_index = $options['match_source_index'] ?? $name_index;
        $lookup_group_ids = $uses_file_groups ? $view->groups()->pluck('id')->all() : [$default_group->id];
        $existing_by_key = $duplicate_mode !== 'add'
            ? $this->buildExistingItemLookup($board, $lookup_group_ids, $match_index, $name_index, $index_to_column)
            : [];

        $caster = new ImportedCellValueCaster($board, $users);
        $item_id_column_ids = $this->itemIdColumnIds($view, BoardColumn::SCOPE_ITEM);
        $subitem_id_column_ids = $subitems_by_row === [] ? [] : $this->itemIdColumnIds($view, BoardColumn::SCOPE_SUBITEM);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $subitems_created = 0;
        $processed = 0;
        $was_cancelled = false;
        /** @var array<int, int> $next_positions group id => next top level position */
        $next_positions = [];

        foreach (array_chunk($rows, self::CHUNK_SIZE, true) as $chunk) {
            if ($this->checkCancelled($isCancelled)) {
                $was_cancelled = true;

                break;
            }

            DB::transaction(function () use (
                $chunk, $board, $row_groups, $groups_by_name, $default_group, $name_index, $index_to_column, $duplicate_mode, $match_index,
                &$existing_by_key, $caster, $item_id_column_ids, $subitem_id_column_ids, $subitems_by_row, $subitem_definitions, $subitem_columns,
                &$created, &$updated, &$skipped, &$subitems_created, &$next_positions,
            ) {
                foreach ($chunk as $row_index => $row) {
                    $name_raw = trim($row[$name_index] ?? '');
                    [$name, $overflow] = $this->splitOverflowingName($name_raw !== '' ? $name_raw : 'Untitled item');

                    [$values, $linked_cells] = $this->castRowValues($index_to_column, $row, $caster);

                    $match_raw = $match_index === $name_index ? $name_raw : trim($row[$match_index] ?? '');
                    $match_key = $this->normalizeForMatch($match_raw);
                    $existing_item = $match_key !== '' ? ($existing_by_key[$match_key] ?? null) : null;

                    if ($existing_item !== null && $duplicate_mode === 'skip') {
                        $skipped++;

                        continue;
                    }

                    if ($existing_item !== null && $duplicate_mode === 'update') {
                        $existing_item->fill(['name' => $name, 'description' => $overflow])->save();

                        foreach ($values as $entry) {
                            $existing_item->values()->updateOrCreate(['column_id' => $entry['column_id']], ['value' => $entry['value']]);
                        }

                        foreach ($linked_cells as [$column, $raw]) {
                            $caster->deferLinkedItems($existing_item->id, $column, $raw);
                        }

                        $updated++;

                        continue;
                    }

                    $group = $groups_by_name[mb_strtolower($row_groups[$row_index] ?? '')] ?? $default_group;
                    $next_positions[$group->id] ??= (int) $group->items()->whereNull('parent_id')->max('position') + 1;

                    $item = $board->items()->create([
                        'group_id' => $group->id,
                        'name' => $name,
                        'description' => $overflow,
                        'position' => $next_positions[$group->id]++,
                    ]);

                    // Like monday.com, an item's Item ID is the item's own id.
                    foreach ($item_id_column_ids as $column_id) {
                        $values[] = ['column_id' => $column_id, 'value' => $item->id];
                    }

                    if ($values !== []) {
                        $item->values()->createMany($values);
                    }

                    foreach ($linked_cells as [$column, $raw]) {
                        $caster->deferLinkedItems($item->id, $column, $raw);
                    }

                    // A new item is also the only one its subitems are added to: an updated
                    // item keeps the subitems it already has rather than getting a second copy.
                    foreach (array_values($subitems_by_row[$row_index] ?? []) as $position => $subitem_row) {
                        $this->createSubitem($board, $item, $position, $subitem_row, $subitem_definitions, $subitem_columns, $subitem_id_column_ids, $caster);
                        $subitems_created++;
                    }

                    $created++;
                }
            });

            $processed += count($chunk);
            $onChunkProcessed($processed, $total);
        }

        // Dependency cells name other rows of this same file, so they can only
        // be linked once every row (up to a cancel) has been written.
        DB::transaction(fn () => $caster->resolveLinkedItems());

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'columns_created' => $columns_created,
            'groups_created' => $groups_created,
            'subitems_created' => $subitems_created,
            'group_id' => $default_group->id,
            'group_created' => $groups_created > 0,
            'processed_rows' => $processed,
            'total_rows' => $total,
            'cancelled' => $was_cancelled,
        ];
    }

    /**
     * Indirection around invoking `$isCancelled` itself — `commit()` calls
     * this the same way at every check, and without this wrapper PHPStan
     * treats two calls to the same unreassigned callable parameter as
     * necessarily returning the same result (true the first time only if
     * the caller made a mistake), which isn't true here: it re-reads
     * `cancel_requested` from the database each time. See
     * https://phpstan.org/blog/remembering-and-forgetting-returned-values.
     *
     * @phpstan-impure
     */
    private function checkCancelled(callable $isCancelled): bool
    {
        return $isCancelled();
    }

    /**
     * @return array{created: int, updated: int, skipped: int, columns_created: int, groups_created: int, subitems_created: int, group_id: int|null, group_created: bool, processed_rows: int, total_rows: int, cancelled: bool}
     */
    private function cancelledResult(int $total): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'columns_created' => 0,
            'groups_created' => 0,
            'subitems_created' => 0,
            'group_id' => null,
            'group_created' => false,
            'processed_rows' => 0,
            'total_rows' => $total,
            'cancelled' => true,
        ];
    }

    /**
     * The {@see BoardColumn} types the "create a new column" mapping mode may
     * target — every type {@see ImportedCellValueCaster} can build from one
     * flat source cell. A Timeline is included since a single cell can hold
     * a whole "2024-01-01 - 2024-01-31" range, a Checklist since each line
     * (or comma-separated entry) becomes a sub-task, a Vote since its cell
     * names the voters, and a Dependency since the items it names are linked
     * by name once every row is in. The Column Center kinds that store a plain
     * value (Country, World clock, Location, Week, Hour, Color) are included
     * too. Connect boards is not, since a new column
     * has no linked board to match names against yet, and neither are the
     * computed types (Formula, Mirror, Auto-number), which never store an
     * imported value.
     *
     * @var array<int, string>
     */
    public const CREATABLE_COLUMN_TYPES = [
        BoardColumn::TYPE_TEXT, BoardColumn::TYPE_LONG_TEXT, BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL,
        BoardColumn::TYPE_PEOPLE, BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE, BoardColumn::TYPE_TAGS,
        BoardColumn::TYPE_DROPDOWN, BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_CHECKBOX, BoardColumn::TYPE_PROGRESS,
        BoardColumn::TYPE_RATING, BoardColumn::TYPE_PHONE, BoardColumn::TYPE_EMAIL, BoardColumn::TYPE_LINK,
        BoardColumn::TYPE_FILES, BoardColumn::TYPE_TIME_TRACKING, BoardColumn::TYPE_CHECKLIST, BoardColumn::TYPE_VOTE,
        BoardColumn::TYPE_DEPENDENCY, BoardColumn::TYPE_COUNTRY, BoardColumn::TYPE_WORLD_CLOCK, BoardColumn::TYPE_LOCATION,
        BoardColumn::TYPE_WEEK, BoardColumn::TYPE_HOUR, BoardColumn::TYPE_COLOR_PICKER,
    ];

    /** Every row lands in the one table the wizard's "Add items to" picker names. */
    public const GROUP_MODE_SINGLE = 'single';

    /** Every row lands in the group it sat under in a monday.com export. */
    public const GROUP_MODE_FILE = 'file';

    /**
     * @return array<int, string>
     */
    public function creatableColumnTypes(): array
    {
        return self::CREATABLE_COLUMN_TYPES;
    }

    // ── Reading ──────────────────────────────────────────────────────────

    private function readerTypeFor(string $extension): string
    {
        return match (strtolower($extension)) {
            'csv', 'txt' => 'Csv',
            'xls' => 'Xls',
            default => 'Xlsx',
        };
    }

    private function findMondayHeaderRow(Worksheet $sheet): ?int
    {
        $highest_row = min($sheet->getHighestRow(), self::HEADER_SCAN_LIMIT);

        for ($row = 1; $row <= $highest_row; $row++) {
            if ($this->isItemHeaderRow($sheet, $row)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Reads a raw monday.com board export. Every group repeats its own "Name |
     * ... | Item ID" header and one header may name columns another doesn't,
     * so the columns are the union of every header, in first-seen order. Each
     * item row remembers the group title above it, and the subitem rows under
     * it ("Subitems | Name | ... | Item ID" header, column A empty) are kept
     * with it, keyed by the item row's index.
     *
     * Every real item row carries a name in column A and monday.com's own
     * (long, numeric) id in its header's trailing column, which tells it apart
     * from a blank separator, a group title, a repeated header and a group's
     * summary row without tracking any of those states explicitly.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, row_groups: array<int, string>, subitem_columns: array<int, array{key: string, label: string}>, subitems: array<int, array<int, array{name: string, data: array<string, string>}>>}
     */
    private function readMondayStyleSheet(Worksheet $sheet, int $header_row): array
    {
        $highest_row = $sheet->getHighestRow();

        /** @var array<string, string> $item_labels union of every item header, column key => label */
        $item_labels = [];
        /** @var array<string, array{key: string, label: string}> $subitem_columns */
        $subitem_columns = [];
        /** @var array<int, array{name: string, data: array<string, string>}> $items */
        $items = [];
        $row_groups = [];
        $subitems = [];

        $item_header = null;
        $subitem_header = null;
        $in_subitems = false;
        $group_name = '';

        for ($row = max(1, $header_row - 1); $row <= $highest_row; $row++) {
            $col_a = $this->cell($sheet, 'A', $row);

            if ($this->isItemHeaderRow($sheet, $row)) {
                $item_header = $this->readHeader($sheet, $row, 'A');
                foreach ($item_header['columns'] as $column) {
                    $item_labels[$column['key']] ??= $column['label'];
                }
                $in_subitems = false;

                continue;
            }

            if ($this->isSubitemHeaderRow($sheet, $row)) {
                $subitem_header = $this->readHeader($sheet, $row, 'B');
                foreach ($subitem_header['columns'] as $column) {
                    $subitem_columns[$column['key']] ??= ['key' => $column['key'], 'label' => $column['label']];
                }
                $in_subitems = true;

                continue;
            }

            if ($in_subitems && $subitem_header !== null) {
                if ($col_a === '' && $this->isMondayId($this->cell($sheet, $subitem_header['id_col'], $row)) && $items !== []) {
                    $name = $this->cell($sheet, 'B', $row);
                    $subitems[count($items) - 1][] = [
                        'name' => $name !== '' ? $name : 'Untitled subitem',
                        'data' => $this->readKeyedRow($sheet, $row, $subitem_header['columns']),
                    ];

                    continue;
                }

                // Not a subitem, so this is the next item, group title or summary row.
                $in_subitems = false;
            }

            if ($item_header !== null && $col_a !== '' && $this->isMondayId($this->cell($sheet, $item_header['id_col'], $row))) {
                $items[] = ['name' => $col_a, 'data' => $this->readKeyedRow($sheet, $row, $item_header['columns'])];
                $row_groups[] = $group_name;

                continue;
            }

            if ($col_a !== '' && $this->isItemHeaderRow($sheet, $row + 1)) {
                $group_name = $col_a;
            }
        }

        $keys = array_keys($item_labels);

        return [
            'headers' => ['Name', ...array_values($item_labels)],
            'rows' => array_map(
                fn (array $item) => [$item['name'], ...array_map(fn (string $key) => $item['data'][$key] ?? '', $keys)],
                $items,
            ),
            'row_groups' => $row_groups,
            'subitem_columns' => array_values($subitem_columns),
            'subitems' => $subitems,
        ];
    }

    /**
     * @param  array<int, array{letter: string, label: string, key: string}>  $columns
     * @return array<string, string>
     */
    private function readKeyedRow(Worksheet $sheet, int $row, array $columns): array
    {
        $data = [];

        foreach ($columns as $column) {
            $value = $this->cell($sheet, $column['letter'], $row);

            if ($value !== '') {
                $data[$column['key']] = $value;
            }
        }

        return $data;
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    private function readFlatSheet(Worksheet $sheet): array
    {
        $highest_row = $sheet->getHighestRow();
        $highest_col_index = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        $headers = [];
        for ($c = 1; $c <= $highest_col_index; $c++) {
            $headers[] = $this->cell($sheet, Coordinate::stringFromColumnIndex($c), 1);
        }

        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }

        foreach ($headers as $index => $label) {
            if ($label === '') {
                $headers[$index] = 'Column '.($index + 1);
            }
        }

        $column_count = count($headers);
        $rows = [];

        for ($row = 2; $row <= $highest_row; $row++) {
            $values = [];
            $has_value = false;

            for ($c = 1; $c <= $column_count; $c++) {
                $value = $this->cell($sheet, Coordinate::stringFromColumnIndex($c), $row);
                if ($value !== '') {
                    $has_value = true;
                }
                $values[] = $value;
            }

            if ($has_value) {
                $rows[] = $values;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, string>
     */
    private function columnValues(array $rows, int $index): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = $row[$index] ?? '';
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    // ── Storage ──────────────────────────────────────────────────────────

    private function tokenPath(string $token): string
    {
        return self::STORAGE_DIR."/{$token}.json";
    }

    private function isValidToken(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9-]{36}$/i', $token);
    }

    private function pruneExpiredImports(): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::STORAGE_DIR)) {
            return;
        }

        $cutoff = now()->subHours(self::TOKEN_TTL_HOURS)->getTimestamp();

        foreach ($disk->files(self::STORAGE_DIR) as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
            }
        }
    }

    // ── Commit helpers ───────────────────────────────────────────────────

    /**
     * The one table every row lands in, and how many tables were created for it (0 or 1).
     *
     * @param  array{target_group_id: int|null, new_group_name: string|null}  $options
     * @return array{0: BoardGroup, 1: int}
     */
    private function resolveSingleTargetGroup(WorkspaceNavigationItem $board, BoardView $view, array $options): array
    {
        if (! empty($options['target_group_id'])) {
            return [$view->groups()->findOrFail($options['target_group_id']), 0];
        }

        return [$this->createGroup($board, $view, $options['new_group_name'] ?: 'Imported items', '#579bfc'), 1];
    }

    /**
     * The groups a monday.com export's rows sat under, keyed by lower-cased
     * name: a group of the tab with the same name is reused, any other is
     * created in file order. Rows without a group fall back to a table named
     * `$fallback_name`, created only when such a row exists.
     *
     * @param  array<int, string>  $row_groups
     * @return array{0: array<string, BoardGroup>, 1: BoardGroup, 2: int} `[groups by name, the first group, how many were created]`
     */
    private function resolveFileGroups(WorkspaceNavigationItem $board, BoardView $view, array $row_groups, ?string $fallback_name): array
    {
        $existing = $view->groups()->get()->keyBy(fn (BoardGroup $group) => mb_strtolower(trim($group->name)));
        $groups_by_name = [];
        $created = 0;

        foreach (array_values(array_unique($row_groups)) as $position => $name) {
            $label = trim($name) !== '' ? $name : ($fallback_name ?: 'Imported items');
            $key = mb_strtolower(trim($name));

            if (isset($groups_by_name[$key])) {
                continue;
            }

            $group = $existing->get(mb_strtolower(trim($label)));

            if ($group === null) {
                $group = $this->createGroup($board, $view, $label, self::OPTION_COLOR_PALETTE[$position % count(self::OPTION_COLOR_PALETTE)]);
                $existing->put(mb_strtolower(trim($label)), $group);
                $created++;
            }

            $groups_by_name[$key] = $group;
        }

        $first_group = reset($groups_by_name);

        if ($first_group === false) {
            $first_group = $this->createGroup($board, $view, $fallback_name ?: 'Imported items', '#579bfc');
            $groups_by_name[''] = $first_group;
            $created++;
        }

        return [$groups_by_name, $first_group, $created];
    }

    private function createGroup(WorkspaceNavigationItem $board, BoardView $view, string $name, string $accent_color): BoardGroup
    {
        return $view->groups()->create([
            'board_id' => $board->id,
            'name' => $this->truncate($name),
            'accent_color' => $accent_color,
            'position' => (int) $view->groups()->max('position') + 1,
        ]);
    }

    /**
     * The tab's subitem columns the file's subitem values are written into:
     * each subitem column of the file is typed from its own values (see
     * {@see CombinesSplitImportColumns::inferColumnDefinitions()}), then
     * matched by name to an existing, writable subitem column or created.
     * Computed types (monday.com's Item ID) are left out, the tab numbers its
     * own subitems.
     *
     * @param  array<int, array{key: string, label: string}>  $header_columns
     * @param  array<int, array<int, array{name: string, data: array<string, string>}>>  $subitems_by_row
     * @param  Collection<int, User>  $users
     * @return array{0: array<int, array{key: string, label: string, type: string, options: array<int, string>, source: array<int, string>, reason: string, config: array<string, mixed>|null}>, 1: array<string, BoardColumn>, 2: int}
     */
    private function resolveSubitemColumns(WorkspaceNavigationItem $board, BoardView $view, array $header_columns, array $subitems_by_row, Collection $users): array
    {
        $collected = [];
        foreach ($subitems_by_row as $subitem_rows) {
            foreach ($subitem_rows as $subitem_row) {
                foreach ($subitem_row['data'] as $key => $value) {
                    $collected[$key][] = $value;
                }
            }
        }

        $definitions = array_values(array_filter(
            $this->inferColumnDefinitions($header_columns, $collected, $users),
            fn (array $definition) => ! in_array($definition['type'], BoardColumn::READ_ONLY_TYPES, true),
        ));

        $existing = $view->columns()
            ->where('scope', BoardColumn::SCOPE_SUBITEM)
            ->whereNotIn('type', BoardColumn::READ_ONLY_TYPES)
            ->get()
            ->keyBy(fn (BoardColumn $column) => mb_strtolower(trim($column->label)));
        $next_position = (int) $view->columns()->where('scope', BoardColumn::SCOPE_SUBITEM)->max('position') + 1;
        $columns = [];
        $created = 0;

        foreach ($definitions as $definition) {
            $column = $existing->get(mb_strtolower(trim($definition['label'])));

            if ($column === null) {
                $column = $view->columns()->create([
                    'board_id' => $board->id,
                    'key' => $this->uniqueColumnKey($view, $definition['label'], BoardColumn::SCOPE_SUBITEM),
                    'label' => $this->truncate($definition['label']),
                    'type' => $definition['type'],
                    'scope' => BoardColumn::SCOPE_SUBITEM,
                    'position' => $next_position++,
                    'width' => $this->widthForType($definition['type']),
                    'config' => $definition['config'] ?? $this->initialColumnConfig($definition['type'], $definition['options']),
                ]);
                $created++;
            }

            $columns[$definition['key']] = $column;
        }

        return [$definitions, $columns, $created];
    }

    /**
     * Creates one subitem under `$parent` with its cell values, written the
     * same way {@see MondayBoardImportService::import()} writes them.
     *
     * @param  array{name: string, data: array<string, string>}  $subitem_row
     * @param  array<int, array{key: string, label: string, type: string, options: array<int, string>, source: array<int, string>, reason: string, config: array<string, mixed>|null}>  $definitions
     * @param  array<string, BoardColumn>  $columns
     * @param  array<int, int>  $item_id_column_ids
     */
    private function createSubitem(
        WorkspaceNavigationItem $board,
        BoardItem $parent,
        int $position,
        array $subitem_row,
        array $definitions,
        array $columns,
        array $item_id_column_ids,
        ImportedCellValueCaster $caster,
    ): void {
        [$name, $overflow] = $this->splitOverflowingName($subitem_row['name']);

        $subitem = $board->items()->create([
            'group_id' => $parent->group_id,
            'parent_id' => $parent->id,
            'name' => $name,
            'description' => $overflow,
            'position' => $position,
        ]);

        $values = array_map(fn (int $column_id) => ['column_id' => $column_id, 'value' => $subitem->id], $item_id_column_ids);

        foreach ($definitions as $definition) {
            $column = $columns[$definition['key']] ?? null;

            if ($column === null) {
                continue;
            }

            $cells = array_map(fn (string $key) => $subitem_row['data'][$key] ?? '', $definition['source']);
            $raw = count($cells) > 1 ? $this->combineSplitCells($column->type, $cells) : $cells[0];

            if ($caster->defersLinkedItems($column)) {
                $caster->deferLinkedItems($subitem->id, $column, $raw);

                continue;
            }

            $value = $caster->cast($column, $raw);

            if ($value !== null) {
                $values[] = ['column_id' => $column->id, 'value' => $value];
            }
        }

        if ($values !== []) {
            $subitem->values()->createMany($values);
        }
    }

    /**
     * Applies every "Map columns" row: `"name"` picks which source column
     * feeds the item's built-in name field, `"map"` points a source column
     * at an existing board column, `"create"` makes a brand-new one (typed
     * from the values actually collected under it, mirroring
     * {@see MondayBoardImportService::inferColumns()}'s option-building), and
     * `"skip"` drops the column from the import entirely.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, array{source_index: int, mode: string, target_column_id: int|null, new_label: string|null, new_type: string|null}>  $mappings
     * @return array{0: int, 1: array<int, BoardColumn>, 2: int}
     */
    private function resolveMappings(WorkspaceNavigationItem $board, BoardView $view, array $headers, array $rows, array $mappings): array
    {
        $name_index = null;
        $index_to_column = [];
        $columns_created = 0;
        $next_position = (int) $view->columns()->where('scope', BoardColumn::SCOPE_ITEM)->max('position') + 1;

        foreach ($mappings as $mapping) {
            $index = $mapping['source_index'];
            if (! array_key_exists($index, $headers)) {
                continue;
            }

            if ($mapping['mode'] === 'name') {
                $name_index ??= $index;

                continue;
            }

            if ($mapping['mode'] === 'map' && $mapping['target_column_id']) {
                $column = BoardColumn::where('id', $mapping['target_column_id'])
                    ->where('board_view_id', $view->id)
                    ->where('scope', BoardColumn::SCOPE_ITEM)
                    ->whereNotIn('type', BoardColumn::READ_ONLY_TYPES)
                    ->first();

                if ($column !== null) {
                    $index_to_column[$index] = $column;
                }

                continue;
            }

            if ($mapping['mode'] === 'create') {
                $label = $this->truncate($mapping['new_label'] ?: $headers[$index]);
                $type = in_array($mapping['new_type'], $this->creatableColumnTypes(), true) ? $mapping['new_type'] : BoardColumn::TYPE_TEXT;

                $column = $view->columns()->create([
                    'board_id' => $board->id,
                    'key' => $this->uniqueColumnKey($view, $label, BoardColumn::SCOPE_ITEM),
                    'label' => $label,
                    'type' => $type,
                    'scope' => BoardColumn::SCOPE_ITEM,
                    'position' => $next_position++,
                    'width' => $this->widthForType($type),
                    'config' => $this->initialColumnConfig($type, $this->optionLabelsFor($type, $this->columnValues($rows, $index)), $this->columnValues($rows, $index)),
                ]);

                $index_to_column[$index] = $column;
                $columns_created++;
            }
        }

        return [$name_index ?? 0, $index_to_column, $columns_created];
    }

    /**
     * Preloads the target table's existing rows into a lookup keyed by a
     * normalized "match" value — the row-by-row loop in `commit()` just
     * looks its own value up in this map instead of querying per row.
     * Returns empty when the dedupe column isn't the name field and wasn't
     * actually mapped to a real column (nothing to compare against).
     *
     * @param  array<int, int>  $group_ids  the tables whose items count as "already on the board"
     * @param  array<int, BoardColumn>  $index_to_column
     * @return array<string, BoardItem>
     */
    private function buildExistingItemLookup(WorkspaceNavigationItem $board, array $group_ids, int $match_index, int $name_index, array $index_to_column): array
    {
        $match_column = $match_index === $name_index ? null : ($index_to_column[$match_index] ?? null);

        if ($match_index !== $name_index && $match_column === null) {
            return [];
        }

        $existing_items = BoardItem::whereIn('group_id', $group_ids)->whereNull('parent_id')->where('is_archived', false)->with('values')->get();
        $tag_labels = $match_column?->type === BoardColumn::TYPE_TAGS
            ? BoardTag::where('board_id', $board->id)->pluck('label', 'id')->all()
            : [];
        $by_key = [];

        foreach ($existing_items as $existing_item) {
            $raw = $match_column === null
                ? $existing_item->name
                : $this->renderValueForMatch($match_column, $existing_item->values->firstWhere('column_id', $match_column->id)?->value, $tag_labels);

            $key = $this->normalizeForMatch($raw);
            if ($key !== '') {
                $by_key[$key] ??= $existing_item;
            }
        }

        return $by_key;
    }

    /**
     * Casts one row's mapped cells. Dependency/Connect boards cells come back
     * separately, raw, since they can only be linked once the row's own item
     * exists (see {@see ImportedCellValueCaster::deferLinkedItems()}).
     *
     * @param  array<int, BoardColumn>  $index_to_column
     * @param  array<int, string>  $row
     * @return array{0: array<int, array{column_id: int, value: mixed}>, 1: array<int, array{0: BoardColumn, 1: string}>}
     */
    private function castRowValues(array $index_to_column, array $row, ImportedCellValueCaster $caster): array
    {
        $values = [];
        $linked_cells = [];

        foreach ($index_to_column as $index => $column) {
            $raw = $row[$index] ?? '';

            if ($caster->defersLinkedItems($column)) {
                $linked_cells[] = [$column, $raw];

                continue;
            }

            $value = $caster->cast($column, $raw);

            if ($value !== null) {
                $values[] = ['column_id' => $column->id, 'value' => $value];
            }
        }

        return [$values, $linked_cells];
    }

    /**
     * The tab's Item ID columns of `$scope`. Like monday.com (and like
     * {@see BoardItemIdAssigner}), an imported item's Item ID is its own id.
     *
     * @return array<int, int>
     */
    private function itemIdColumnIds(BoardView $view, string $scope): array
    {
        return $view->columns()
            ->where('scope', $scope)
            ->where('type', BoardColumn::TYPE_AUTO_NUMBER)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Merges the columns {@see findSplitColumnGroups()} recognizes into one
     * column each, placed where the group's first member was.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, combined_from: array<int, array<int, string>>}
     */
    private function combineSplitColumns(array $headers, array $rows): array
    {
        $groups = $this->findSplitColumnGroups(array_map(
            fn (int $index) => ['label' => $headers[$index], 'values' => $this->columnValues($rows, $index)],
            array_keys($headers),
        ));

        if ($groups === []) {
            return ['headers' => $headers, 'rows' => $rows, 'combined_from' => []];
        }

        $group_by_first_member = [];
        $consumed = [];
        foreach ($groups as $group) {
            $group_by_first_member[$group['members'][0]] = $group;
            foreach ($group['members'] as $member) {
                $consumed[$member] = true;
            }
        }

        $new_headers = [];
        $combined_from = [];
        /** @var array<int, array{type: string, label: string, members: array<int, int>, reason: string}|int> $layout each new column: a merged group, or the old index it copies */
        $layout = [];

        foreach ($headers as $index => $label) {
            if (isset($group_by_first_member[$index])) {
                $group = $group_by_first_member[$index];
                $combined_from[count($new_headers)] = array_map(fn (int $member) => $headers[$member], $group['members']);
                $new_headers[] = $group['label'];
                $layout[] = $group;

                continue;
            }

            if (! isset($consumed[$index])) {
                $new_headers[] = $label;
                $layout[] = $index;
            }
        }

        $new_rows = array_map(fn (array $row) => array_map(
            fn (array|int $source) => is_int($source)
                ? ($row[$source] ?? '')
                : $this->combineSplitCells($source['type'], array_map(fn (int $member) => $row[$member] ?? '', $source['members'])),
            $layout,
        ), $rows);

        return ['headers' => $new_headers, 'rows' => $new_rows, 'combined_from' => $combined_from];
    }

    private function optionLabelFor(BoardColumn $column, string $id): ?string
    {
        foreach (data_get($column->config, 'options', []) as $option) {
            if ((string) $option['id'] === $id) {
                return $option['label'];
            }
        }

        return null;
    }

    /**
     * The write-side counterpart of `castRowValues` — renders an already-
     * stored column value back into the same kind of plain string a raw
     * import cell would be, so an existing item's value can be compared
     * against an incoming row's raw text for dedupe matching.
     *
     * @param  array<int, string>  $tag_labels  board tag id => label, for a Tags column
     */
    private function renderValueForMatch(BoardColumn $column, mixed $value, array $tag_labels = []): string
    {
        if ($value === null) {
            return '';
        }

        return match ($column->type) {
            BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL => $this->optionLabelFor($column, (string) $value) ?? '',
            BoardColumn::TYPE_TAGS => implode(',', array_map(
                fn ($id) => is_numeric($id) ? ($tag_labels[(int) $id] ?? '') : '',
                is_array($value) ? $value : []
            )),
            BoardColumn::TYPE_DROPDOWN => implode(',', array_map(
                fn ($id) => $this->optionLabelFor($column, (string) $id) ?? '',
                is_array($value) ? $value : []
            )),
            BoardColumn::TYPE_PEOPLE => implode(',', is_array($value) ? $value : []),
            BoardColumn::TYPE_CHECKBOX => $value ? '1' : '',
            BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_PROGRESS, BoardColumn::TYPE_RATING => is_numeric($value) ? (string) (float) $value : (string) $value,
            BoardColumn::TYPE_LINK => is_array($value) ? (string) ($value['url'] ?? '') : (string) $value,
            BoardColumn::TYPE_TIMELINE => is_array($value) ? trim(($value['start'] ?? '').' - '.($value['end'] ?? ''), ' -') : '',
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    private function normalizeForMatch(string $raw): string
    {
        return mb_strtolower(trim($raw));
    }

    /**
     * The option labels a freshly-created Status/Label/Dropdown column starts
     * with: each distinct cell for a single-select, each distinct
     * comma-separated token for a Dropdown.
     *
     * @param  array<int, string>  $values  every non-empty raw cell collected under this column
     * @return array<int, string>
     */
    private function optionLabelsFor(string $type, array $values): array
    {
        if ($type === BoardColumn::TYPE_DROPDOWN) {
            $tokens = [];
            foreach ($values as $value) {
                foreach ($this->splitList($value) as $token) {
                    $tokens[$token] = true;
                }
            }

            return array_keys($tokens);
        }

        return array_values(array_unique($values));
    }

    private function uniqueColumnKey(BoardView $view, string $label, string $scope): string
    {
        $base = (string) Str::of($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');
        if ($base === '') {
            $base = 'field';
        }
        $base = Str::limit($base, 90, '');

        $key = $base;
        $suffix = 2;

        while (BoardColumn::where('board_view_id', $view->id)->where('scope', $scope)->where('key', $key)->exists()) {
            $key = $base.'_'.$suffix;
            $suffix++;
        }

        return $key;
    }

    /**
     * `board_items.name` is a `varchar(255)` column — an overflowing raw
     * name moves into the item's `description` instead of failing the row
     * or silently truncating data, mirroring
     * {@see MondayBoardImportService::splitOverflowingName()}.
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

    private function truncate(string $value, int $limit = 255): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }
}
