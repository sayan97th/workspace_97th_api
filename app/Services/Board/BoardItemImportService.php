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
use App\Models\BoardItemValue;
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
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, combined_from: array<int, array<int, string>>}
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
            : $this->readFlatSheet($sheet);

        return $this->combineSplitColumns($parsed['headers'], $parsed['rows']);
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
     * need the original file again — only ever this token.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    public function storeParsedImport(string $file_name, array $headers, array $rows): string
    {
        $this->pruneExpiredImports();

        $token = (string) Str::uuid();

        $payload = [
            'file_name' => $file_name,
            'headers' => $headers,
            'rows' => $rows,
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
     * @param  array{headers: array<int, string>, rows: array<int, array<int, string>>}  $parsed
     * @param  array{
     *     target_group_id: int|null,
     *     new_group_name: string|null,
     *     mappings: array<int, array{source_index: int, mode: string, target_column_id: int|null, new_label: string|null, new_type: string|null}>,
     *     duplicate_mode: string,
     *     match_source_index: int|null,
     * }  $options
     * @param  callable(int $processed, int $total): void  $onChunkProcessed
     * @param  callable(): bool  $isCancelled
     * @return array{created: int, updated: int, skipped: int, columns_created: int, group_id: int|null, group_created: bool, processed_rows: int, total_rows: int, cancelled: bool}
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
        // columns nobody asked to keep, so this skips `resolveTargetGroup()`/
        // `resolveMappings()` entirely rather than creating them first.
        if ($this->checkCancelled($isCancelled)) {
            return $this->cancelledResult($total);
        }

        [$group, $group_created] = DB::transaction(fn () => $this->resolveTargetGroup($board, $view, $options));
        [$name_index, $index_to_column, $columns_created] = DB::transaction(
            fn () => $this->resolveMappings($board, $view, $headers, $rows, $options['mappings'])
        );

        $duplicate_mode = $options['duplicate_mode'];
        $match_index = $options['match_source_index'] ?? $name_index;
        $existing_by_key = $duplicate_mode !== 'add'
            ? $this->buildExistingItemLookup($group, $match_index, $name_index, $index_to_column)
            : [];

        $caster = new ImportedCellValueCaster($board, User::all());
        $auto_number_counters = $this->autoNumberCounters($view);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $processed = 0;
        $was_cancelled = false;
        $next_position = (int) $group->items()->whereNull('parent_id')->max('position') + 1;

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            if ($this->checkCancelled($isCancelled)) {
                $was_cancelled = true;

                break;
            }

            DB::transaction(function () use (
                $chunk, $board, $group, $name_index, $index_to_column, $duplicate_mode, $match_index,
                &$existing_by_key, $caster, &$auto_number_counters, &$created, &$updated, &$skipped, &$next_position,
            ) {
                foreach ($chunk as $row) {
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

                    $item = $board->items()->create([
                        'group_id' => $group->id,
                        'name' => $name,
                        'description' => $overflow,
                        'position' => $next_position++,
                    ]);

                    // Auto-number columns are numbered here in memory rather than
                    // through `BoardItemValueService::assignAutoNumbers()`, which
                    // re-reads the column's highest number once per item.
                    foreach ($auto_number_counters as $column_id => $last_number) {
                        $auto_number_counters[$column_id] = $last_number + 1;
                        $values[] = ['column_id' => $column_id, 'value' => $last_number + 1];
                    }

                    if ($values !== []) {
                        $item->values()->createMany($values);
                    }

                    foreach ($linked_cells as [$column, $raw]) {
                        $caster->deferLinkedItems($item->id, $column, $raw);
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
            'group_id' => $group->id,
            'group_created' => $group_created,
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
     * @return array{created: int, updated: int, skipped: int, columns_created: int, group_id: int|null, group_created: bool, processed_rows: int, total_rows: int, cancelled: bool}
     */
    private function cancelledResult(int $total): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'columns_created' => 0,
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
     * by name once every row is in. Connect boards is not, since a new column
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
        BoardColumn::TYPE_DEPENDENCY,
    ];

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
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    private function readMondayStyleSheet(Worksheet $sheet, int $header_row): array
    {
        $header = $this->readHeader($sheet, $header_row, 'A');
        $headers = ['Name', ...array_map(fn (array $column) => $column['label'], $header['columns'])];

        $highest_row = $sheet->getHighestRow();
        $rows = [];

        for ($row = $header_row + 1; $row <= $highest_row; $row++) {
            $id_value = $this->cell($sheet, $header['id_col'], $row);

            // Every real item row carries a name in column A and monday.com's
            // own (long, numeric) id in the header's trailing column — this
            // single check is what tells an item row apart from a blank
            // separator, a group-title row, a repeated header row, a group's
            // summary row and any subitem row (column A empty), without
            // tracking any of those states explicitly.
            if ($this->cell($sheet, 'A', $row) === '' || ! $this->isMondayId($id_value)) {
                continue;
            }

            $values = [$this->cell($sheet, 'A', $row)];
            foreach ($header['columns'] as $column) {
                $values[] = $this->cell($sheet, $column['letter'], $row);
            }
            $rows[] = $values;
        }

        return ['headers' => $headers, 'rows' => $rows];
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
     * @param  array{target_group_id: int|null, new_group_name: string|null}  $options
     * @return array{0: BoardGroup, 1: bool}
     */
    private function resolveTargetGroup(WorkspaceNavigationItem $board, BoardView $view, array $options): array
    {
        if (! empty($options['target_group_id'])) {
            return [$view->groups()->findOrFail($options['target_group_id']), false];
        }

        $group = $view->groups()->create([
            'board_id' => $board->id,
            'name' => $this->truncate($options['new_group_name'] ?: 'Imported items'),
            'accent_color' => '#579bfc',
            'position' => (int) $view->groups()->max('position') + 1,
        ]);

        return [$group, true];
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
                    'key' => $this->uniqueColumnKey($view, $label),
                    'label' => $label,
                    'type' => $type,
                    'scope' => BoardColumn::SCOPE_ITEM,
                    'position' => $next_position++,
                    'width' => $this->widthForType($type),
                    'config' => $this->initialColumnConfig($type, $this->optionLabelsFor($type, $this->columnValues($rows, $index))),
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
     * @param  array<int, BoardColumn>  $index_to_column
     * @return array<string, BoardItem>
     */
    private function buildExistingItemLookup(BoardGroup $group, int $match_index, int $name_index, array $index_to_column): array
    {
        $match_column = $match_index === $name_index ? null : ($index_to_column[$match_index] ?? null);

        if ($match_index !== $name_index && $match_column === null) {
            return [];
        }

        $existing_items = $group->items()->whereNull('parent_id')->where('is_archived', false)->with('values')->get();
        $tag_labels = $match_column?->type === BoardColumn::TYPE_TAGS
            ? BoardTag::where('board_id', $group->board_id)->pluck('label', 'id')->all()
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
     * The highest number each of the tab's item-scope Auto-number columns has
     * handed out so far, keyed by column id: `commit()` numbers every item it
     * creates on from there, the same way creating an item by hand would.
     *
     * @return array<int, int>
     */
    private function autoNumberCounters(BoardView $view): array
    {
        $counters = [];

        $column_ids = $view->columns()
            ->where('scope', BoardColumn::SCOPE_ITEM)
            ->where('type', BoardColumn::TYPE_AUTO_NUMBER)
            ->pluck('id');

        foreach ($column_ids as $column_id) {
            $counters[$column_id] = (int) BoardItemValue::where('column_id', $column_id)
                ->pluck('value')
                ->map(fn ($value) => is_numeric($value) ? (int) $value : 0)
                ->max();
        }

        return $counters;
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

    private function uniqueColumnKey(BoardView $view, string $label): string
    {
        $base = (string) Str::of($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');
        if ($base === '') {
            $base = 'field';
        }
        $base = Str::limit($base, 90, '');

        $key = $base;
        $suffix = 2;

        while (BoardColumn::where('board_view_id', $view->id)->where('scope', BoardColumn::SCOPE_ITEM)->where('key', $key)->exists()) {
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
