<?php

namespace App\Services\Board;

use App\Concerns\InfersBoardColumnTypes;
use App\Concerns\ReadsSpreadsheetSheets;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
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
 * to touch the uploaded file again.
 */
class BoardItemImportService
{
    use InfersBoardColumnTypes, ReadsSpreadsheetSheets;

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
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
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

        return $header_row !== null
            ? $this->readMondayStyleSheet($sheet, $header_row)
            : $this->readFlatSheet($sheet);
    }

    /**
     * One entry per source column: its sample values and a guessed
     * {@see BoardColumn} type — feeds the "Map columns" step's column list
     * and its "create a new column" mapping mode's pre-filled type.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, array{index: int, label: string, sample_values: array<int, string>, suggested_type: string}>
     */
    public function buildSourceColumns(array $headers, array $rows): array
    {
        $columns = [];

        foreach ($headers as $index => $label) {
            $non_empty = $this->columnValues($rows, $index);
            [$type] = $this->inferColumnType($label, $non_empty);

            $columns[] = [
                'index' => $index,
                'label' => $label,
                'sample_values' => array_slice(array_values(array_unique($non_empty)), 0, 4),
                'suggested_type' => $type,
            ];
        }

        return $columns;
    }

    /**
     * Pre-fills the "Map columns" step: the column literally labeled "Name"
     * (or, failing that, the first column — every monday.com export's own
     * convention) is mapped to the item's built-in name field, and any other
     * column whose label case-insensitively matches an existing board column
     * is mapped straight to it. Everything else starts unmapped, left for
     * the "Unmapped columns" default / a manual pick.
     *
     * @param  array<int, string>  $headers
     * @param  Collection<int, BoardColumn>  $existing_columns
     * @return array<int, array{source_index: int, mode: string, target_column_id: int|null}>
     */
    public function suggestMappings(array $headers, Collection $existing_columns): array
    {
        $name_index = 0;
        foreach ($headers as $index => $label) {
            if (mb_strtolower(trim($label)) === 'name') {
                $name_index = $index;
                break;
            }
        }

        $columns_by_label = $existing_columns->keyBy(fn (BoardColumn $column) => mb_strtolower(trim($column->label)));

        $mappings = [];
        foreach ($headers as $index => $label) {
            if ($index === $name_index) {
                $mappings[] = ['source_index' => $index, 'mode' => 'name', 'target_column_id' => null];

                continue;
            }

            $match = $columns_by_label->get(mb_strtolower(trim($label)));
            $mappings[] = [
                'source_index' => $index,
                'mode' => $match !== null ? 'map' : 'skip',
                'target_column_id' => $match?->id,
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

    /**
     * Creates (or reuses) the target table, its "create a new column"
     * mappings, and every row as a board item — updating/skipping rows that
     * match an existing item when `duplicate_mode` isn't `"add"`. Runs
     * inside the caller's transaction (see `BoardImportController::commit()`).
     *
     * @param  array{headers: array<int, string>, rows: array<int, array<int, string>>}  $parsed
     * @param  array{
     *     target_group_id: int|null,
     *     new_group_name: string|null,
     *     mappings: array<int, array{source_index: int, mode: string, target_column_id: int|null, new_label: string|null, new_type: string|null}>,
     *     duplicate_mode: string,
     *     match_source_index: int|null,
     * }  $options
     * @return array{created: int, updated: int, skipped: int, columns_created: int, group_id: int, group_created: bool}
     */
    public function commit(WorkspaceNavigationItem $board, BoardView $view, array $parsed, array $options): array
    {
        $headers = $parsed['headers'];
        $rows = array_slice($parsed['rows'], 0, self::MAX_ROWS);

        [$group, $group_created] = $this->resolveTargetGroup($board, $view, $options);
        [$name_index, $index_to_column, $columns_created] = $this->resolveMappings($board, $view, $headers, $rows, $options['mappings']);

        $duplicate_mode = $options['duplicate_mode'];
        $match_index = $options['match_source_index'] ?? $name_index;
        $existing_by_key = $duplicate_mode !== 'add'
            ? $this->buildExistingItemLookup($group, $match_index, $name_index, $index_to_column)
            : [];

        $users = User::all();
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $next_position = (int) $group->items()->whereNull('parent_id')->max('position') + 1;

        foreach ($rows as $row) {
            $name_raw = trim($row[$name_index] ?? '');
            [$name, $overflow] = $this->splitOverflowingName($name_raw !== '' ? $name_raw : 'Untitled item');

            $values = $this->castRowValues($index_to_column, $row, $users);

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

                $updated++;

                continue;
            }

            $item = $board->items()->create([
                'group_id' => $group->id,
                'name' => $name,
                'description' => $overflow,
                'position' => $next_position++,
            ]);

            if ($values !== []) {
                $item->values()->createMany($values);
            }

            $created++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'columns_created' => $columns_created,
            'group_id' => $group->id,
            'group_created' => $group_created,
        ];
    }

    /**
     * The subset of {@see BoardColumn} types the "create a new column"
     * mapping mode may target — every real type except `timeline`/
     * `dependency`, which need more than one flat source column's worth of
     * data to mean anything.
     *
     * @return array<int, string>
     */
    public function creatableColumnTypes(): array
    {
        return [
            BoardColumn::TYPE_TEXT, BoardColumn::TYPE_LONG_TEXT, BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL,
            BoardColumn::TYPE_PEOPLE, BoardColumn::TYPE_DATE, BoardColumn::TYPE_TAGS, BoardColumn::TYPE_DROPDOWN,
            BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_CHECKBOX, BoardColumn::TYPE_PROGRESS, BoardColumn::TYPE_PHONE,
            BoardColumn::TYPE_EMAIL,
        ];
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

            // Every real item row carries monday.com's own numeric id in the
            // header's trailing column — this single check is what tells an
            // item row apart from a blank separator, a group-title row, a
            // repeated header row for the next group, and (since a
            // subitem's own id lives in a different column entirely) any
            // subitem row, without tracking any of those states explicitly.
            if ($id_value === '' || ! ctype_digit($id_value)) {
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
                    'config' => $this->configFor($type, $this->columnValues($rows, $index)),
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
        $by_key = [];

        foreach ($existing_items as $existing_item) {
            $raw = $match_column === null
                ? $existing_item->name
                : $this->renderValueForMatch($match_column, $existing_item->values->firstWhere('column_id', $match_column->id)?->value);

            $key = $this->normalizeForMatch($raw);
            if ($key !== '') {
                $by_key[$key] ??= $existing_item;
            }
        }

        return $by_key;
    }

    /**
     * @param  array<int, BoardColumn>  $index_to_column
     * @param  array<int, string>  $row
     * @param  Collection<int, User>  $users
     * @return array<int, array{column_id: int, value: mixed}>
     */
    private function castRowValues(array $index_to_column, array $row, Collection $users): array
    {
        $values = [];

        foreach ($index_to_column as $index => $column) {
            $raw = trim($row[$index] ?? '');
            if ($raw === '') {
                continue;
            }

            $value = match ($column->type) {
                BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_PROGRESS => is_numeric($raw) ? (float) $raw : null,
                BoardColumn::TYPE_CHECKBOX => true,
                BoardColumn::TYPE_DATE => $this->parseDate($raw),
                BoardColumn::TYPE_PEOPLE => $this->resolvePersonIds($raw, $users),
                BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL => $this->findOptionId($column, $raw),
                BoardColumn::TYPE_TAGS, BoardColumn::TYPE_DROPDOWN => $this->optionIdsForLabels($column, $this->splitList($raw)),
                BoardColumn::TYPE_TIMELINE, BoardColumn::TYPE_DEPENDENCY => null,
                default => $raw,
            };

            if ($value === null || $value === []) {
                continue;
            }

            $values[] = ['column_id' => $column->id, 'value' => $value];
        }

        return $values;
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
            $id = $this->findOptionId($column, $label);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
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
     * @param  Collection<int, User>  $users
     * @return array<int, string>
     */
    private function resolvePersonIds(string $raw, Collection $users): array
    {
        $ids = [];

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
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The write-side counterpart of `castRowValues` — renders an already-
     * stored column value back into the same kind of plain string a raw
     * import cell would be, so an existing item's value can be compared
     * against an incoming row's raw text for dedupe matching.
     */
    private function renderValueForMatch(BoardColumn $column, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return match ($column->type) {
            BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL => $this->optionLabelFor($column, (string) $value) ?? '',
            BoardColumn::TYPE_TAGS, BoardColumn::TYPE_DROPDOWN => implode(',', array_map(
                fn ($id) => $this->optionLabelFor($column, (string) $id) ?? '',
                is_array($value) ? $value : []
            )),
            BoardColumn::TYPE_PEOPLE => implode(',', is_array($value) ? $value : []),
            BoardColumn::TYPE_CHECKBOX => $value ? '1' : '',
            BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_PROGRESS => is_numeric($value) ? (string) (float) $value : (string) $value,
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    private function normalizeForMatch(string $raw): string
    {
        return mb_strtolower(trim($raw));
    }

    /**
     * @param  array<int, string>  $values  every non-empty raw cell collected under this column
     * @return array<string, mixed>|null
     */
    private function configFor(string $type, array $values): ?array
    {
        if (! in_array($type, [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_TAGS, BoardColumn::TYPE_DROPDOWN], true)) {
            return null;
        }

        if (in_array($type, [BoardColumn::TYPE_TAGS, BoardColumn::TYPE_DROPDOWN], true)) {
            $tokens = [];
            foreach ($values as $value) {
                foreach ($this->splitList($value) as $token) {
                    $tokens[$token] = true;
                }
            }
            $labels = array_keys($tokens);
        } else {
            $labels = array_values(array_unique($values));
        }

        if ($labels === []) {
            return null;
        }

        return ['options' => $this->buildOptions(array_slice($labels, 0, 60))];
    }

    private function widthForType(string $type): int
    {
        return match ($type) {
            BoardColumn::TYPE_PEOPLE => 160,
            BoardColumn::TYPE_STATUS => 170,
            BoardColumn::TYPE_LABEL => 130,
            BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_PROGRESS => 110,
            BoardColumn::TYPE_TAGS, BoardColumn::TYPE_DROPDOWN => 200,
            BoardColumn::TYPE_DATE => 150,
            BoardColumn::TYPE_CHECKBOX => 90,
            BoardColumn::TYPE_LONG_TEXT => 240,
            default => 160,
        };
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
