<?php

namespace App\Services\Board;

use App\Concerns\CombinesSplitImportColumns;
use App\Concerns\InfersBoardColumnTypes;
use App\Concerns\ReadsSpreadsheetSheets;
use App\Http\Controllers\Board\BoardItemCellFileController;
use App\Jobs\ProcessBoardImportJob;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardImportJob;
use App\Models\BoardItem;
use App\Models\BoardTag;
use App\Models\BoardView;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\Import\SpreadsheetEmbeddedAssetReader;
use App\Services\Board\Import\SpreadsheetListOptionsReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

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
 *
 * @phpstan-type StagedAsset array{id: string, file_name: string, mime_type: string, kind: string, size_bytes: int, width: int|null, height: int|null, staged_path: string, url: string}
 * @phpstan-type SourceColumn array{index: int, label: string, sample_values: array<int, string>, suggested_type: string, detection_reason: string, compatible_types: array<int, string>, combined_from: array<int, string>, filled_count: int, distinct_count: int, declared_options: array<int, array{label: string, color: string}>, option_preset: string|null, assets: array{file_count: int, image_count: int, cell_count: int, total_bytes: int, previews: array<int, array{file_name: string, url: string|null, mime_type: string, kind: string, width: int|null, height: int|null}>}|null, people_match: array{matched_count: int, total_count: int, unmatched: array<int, string>}|null}
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

    /** Where an upload's embedded files wait on the app disk between "analyze" and the job that attaches them. */
    private const ASSET_STAGING_DIR = 'board-imports';

    /** How many embedded files of one column the wizard previews. */
    private const ASSET_PREVIEW_LIMIT = 6;

    /**
     * Parses one sheet (the first by default) of an uploaded .csv/.xlsx/.xls file into a flat
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
     * A plain table also brings what the spreadsheet knows beyond cell text:
     * `column_options` holds, per column index, the choices of the dropdown
     * list (data validation) its cells pick from, and `cell_assets` the files
     * embedded in its cells (pictures, Excel's in-cell pictures, inserted
     * documents) keyed by row index then column index, still holding their
     * bytes until {@see stageParsedImport()} moves them to storage. `sheets`
     * lists every sheet of the workbook, so the wizard can switch to another.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, combined_from: array<int, array<int, string>>, row_groups: array<int, string>, subitem_columns: array<int, array{key: string, label: string}>, subitems: array<int, array<int, array{name: string, data: array<string, string>}>>, sheets: array<int, array{index: int, name: string, row_count: int, image_count: int}>, sheet_index: int, column_options: array<int, array<int, string>>, cell_assets: array<int, array<int, array<int, array<string, mixed>>>>}
     */
    public function readSpreadsheet(UploadedFile $file, int $sheet_index = 0): array
    {
        $reader_type = $this->readerTypeFor($file->getClientOriginalExtension());
        $file_path = (string) $file->getRealPath();

        // Deliberately NOT `setReadDataOnly(true)` — a date-formatted cell's
        // number format code lives in its style, and `getFormattedValue()`
        // (what `cell()` below reads) needs that to render "3/1/2022"
        // instead of the raw Excel date serial underneath it. It also keeps
        // the pictures and dropdown lists, which the read below brings in.
        $spreadsheet = IOFactory::createReader($reader_type)->load($file_path);
        $sheet_index = max(0, min($sheet_index, $spreadsheet->getSheetCount() - 1));
        $sheet = $spreadsheet->getSheet($sheet_index);

        $header_row = $this->findMondayHeaderRow($sheet);
        $extras = ['column_options' => [], 'cell_assets' => []];

        if ($header_row !== null) {
            $parsed = $this->readMondayStyleSheet($sheet, $header_row);
        } else {
            $assets_by_coordinate = $reader_type === 'Csv' ? [] : $this->readEmbeddedAssets($sheet, $file_path, $sheet_index);
            $parsed = [...$this->readFlatSheet($sheet, $assets_by_coordinate), 'row_groups' => [], 'subitem_columns' => [], 'subitems' => []];
            $list_options = $reader_type === 'Csv' ? [] : (new SpreadsheetListOptionsReader)->read($sheet);
            $extras = $this->collectCellExtras($parsed, $list_options, $assets_by_coordinate);
        }

        $combined = $this->combineSplitColumns($parsed['headers'], $parsed['rows']);

        return [
            'headers' => $combined['headers'],
            'rows' => $combined['rows'],
            'combined_from' => $combined['combined_from'],
            'row_groups' => $parsed['row_groups'],
            'subitem_columns' => $parsed['subitem_columns'],
            'subitems' => $parsed['subitems'],
            'sheets' => $this->describeSheets($spreadsheet),
            'sheet_index' => $sheet_index,
            'column_options' => $this->remapColumnKeys($extras['column_options'], $combined['index_map']),
            'cell_assets' => array_map(fn (array $row_assets) => $this->remapColumnKeys($row_assets, $combined['index_map']), $extras['cell_assets']),
        ];
    }

    /**
     * Every sheet of the workbook with how many data rows (below its header
     * row) and pictures it holds, for the wizard's sheet picker. A row is
     * only counted when a cell in it says something: rows holding nothing
     * but unticked checkboxes (a sheet's pre-filled FALSE cells) are not.
     *
     * @return array<int, array{index: int, name: string, row_count: int, image_count: int}>
     */
    private function describeSheets(Spreadsheet $spreadsheet): array
    {
        $sheets = [];

        foreach ($spreadsheet->getWorksheetIterator() as $index => $sheet) {
            $rows_with_values = [];
            $cells = $sheet->getCellCollection();

            foreach ($cells->getCoordinates() as $coordinate) {
                $value = $cells->get($coordinate)?->getValue();
                $row = (int) preg_replace('/^[A-Z]+/', '', $coordinate);

                if ($row > 1 && $value !== null && $value !== false && trim((string) $value) !== '') {
                    $rows_with_values[$row] = true;
                }
            }

            $sheets[] = [
                'index' => $index,
                'name' => $sheet->getTitle(),
                'row_count' => count($rows_with_values),
                'image_count' => count($sheet->getDrawingCollection()),
            ];
        }

        return $sheets;
    }

    /**
     * @return array<string, array<int, array{file_name: string, mime_type: string, kind: string, contents: string}>>
     */
    private function readEmbeddedAssets(Worksheet $sheet, string $file_path, int $sheet_index): array
    {
        try {
            return (new SpreadsheetEmbeddedAssetReader)->read($sheet, $file_path, $sheet_index);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Turns the column letter keyed dropdown lists and the cell keyed
     * embedded files into the parsed grid's own column and row indexes. A
     * file whose cell isn't part of the grid (above the header, or in a
     * dropped column) is left out. A generic picture name ("image.png")
     * becomes one naming its column and row, "screenshot-row-9.png".
     *
     * @param  array{headers: array<int, string>, letters: array<int, string>, row_numbers: array<int, int>}  $parsed
     * @param  array<string, array<int, string>>  $list_options
     * @param  array<string, array<int, array{file_name: string, mime_type: string, kind: string, contents: string}>>  $assets_by_coordinate
     * @return array{column_options: array<int, array<int, string>>, cell_assets: array<int, array<int, array<int, array<string, mixed>>>>}
     */
    private function collectCellExtras(array $parsed, array $list_options, array $assets_by_coordinate): array
    {
        $index_by_letter = array_flip($parsed['letters']);
        $row_index_by_number = array_flip($parsed['row_numbers']);
        $column_options = [];
        $cell_assets = [];

        foreach ($list_options as $letter => $options) {
            if (isset($index_by_letter[$letter])) {
                $column_options[$index_by_letter[$letter]] = $options;
            }
        }

        foreach ($assets_by_coordinate as $coordinate => $assets) {
            [$letter, $row_number] = Coordinate::coordinateFromString($coordinate);
            $column_index = $index_by_letter[$letter] ?? null;
            $row_index = $row_index_by_number[(int) $row_number] ?? null;

            if ($column_index === null || $row_index === null) {
                continue;
            }

            foreach ($assets as $position => $asset) {
                $cell_assets[$row_index][$column_index][] = [
                    ...$asset,
                    'file_name' => $this->assetFileName($asset['file_name'], $parsed['headers'][$column_index] ?? '', (int) $row_number, $position),
                ];
            }
        }

        ksort($cell_assets);

        return ['column_options' => $column_options, 'cell_assets' => $cell_assets];
    }

    private function assetFileName(string $file_name, string $header, int $row_number, int $position): string
    {
        $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $base_name = pathinfo($file_name, PATHINFO_FILENAME);

        if (! in_array(mb_strtolower($base_name), ['image', 'document'], true)) {
            return $file_name;
        }

        $prefix = trim((string) preg_replace('/[^a-z0-9]+/', '-', Str::ascii(mb_strtolower($header))), '-');

        return ($prefix !== '' ? $prefix : $base_name)."-row-{$row_number}".($position > 0 ? '-'.($position + 1) : '').".{$extension}";
    }

    /**
     * Re-keys a column index keyed map after {@see combineSplitColumns()}
     * merged or moved columns.
     *
     * @template T
     *
     * @param  array<int, T>  $by_column
     * @param  array<int, int>  $index_map  old column index => new column index
     * @return array<int, T>
     */
    private function remapColumnKeys(array $by_column, array $index_map): array
    {
        $remapped = [];

        foreach ($by_column as $index => $value) {
            if (isset($index_map[$index]) && ! isset($remapped[$index_map[$index]])) {
                $remapped[$index_map[$index]] = $value;
            }
        }

        return $remapped;
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
     * What the sheet knows beyond the text is reported too: a column whose
     * cells pick from a dropdown list is typed from that list (see
     * {@see InfersBoardColumnTypes::inferFromDeclaredOptions()}) and lists its
     * `declared_options` with the colors they get, `option_preset` names a
     * priority column, a column with embedded files becomes a Files column
     * and previews them (`assets`), and a column that may become People says
     * which names match no user (`people_match`).
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, array<int, string>>  $combined_from  {@see readSpreadsheet()}'s own `combined_from`
     * @param  array<int, array<int, string>>  $column_options  {@see readSpreadsheet()}'s own `column_options`
     * @param  array<int, array<int, array<int, StagedAsset>>>  $cell_assets  {@see stageParsedImport()}'s staged `cell_assets`
     * @return array<int, SourceColumn>
     */
    public function buildSourceColumns(array $headers, array $rows, array $combined_from = [], array $column_options = [], array $cell_assets = []): array
    {
        $columns = [];
        $users = User::all();
        $assets_by_column = $this->assetsByColumn($cell_assets);

        foreach ($headers as $index => $label) {
            $non_empty = $this->columnValues($rows, $index);
            [$type, , $reason] = $this->inferColumnType($label, $non_empty, $users);
            $declared = $column_options[$index] ?? [];
            $assets = $assets_by_column[$index] ?? ['cells' => [], 'files' => []];
            $compatible = $this->compatibleColumnTypes(self::CREATABLE_COLUMN_TYPES, $non_empty, $users);

            if ($declared !== []) {
                $declared_guess = $this->inferFromDeclaredOptions($label, $declared, $non_empty);

                if ($declared_guess !== null) {
                    [$type, $reason] = $declared_guess;
                    $compatible = array_values(array_unique([...$compatible, BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DROPDOWN]));
                }
            }

            $asset_cell_count = count($assets['cells']);
            $filled_rows = $asset_cell_count === 0 ? count($non_empty) : count($this->filledRowIndexes($rows, $index) + $assets['cells']);

            if ($asset_cell_count > 0) {
                // Embedded files only fit a Files column, along with any links typed next to them.
                $all_links = $this->shareMatching($non_empty, fn (string $value) => $this->parseLinks($value) !== null) === 1.0;
                $compatible = $non_empty === [] || $all_links ? [BoardColumn::TYPE_FILES] : [];

                if ($asset_cell_count >= count($non_empty) - $this->countLinks($non_empty)) {
                    $type = BoardColumn::TYPE_FILES;
                    $reason = $this->describeAssets($assets['files'], $this->countLinks($non_empty));
                }
            }

            $preset = in_array($type, [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DROPDOWN], true)
                ? $this->optionPresetFor($label, $declared !== [] ? $declared : array_values(array_unique($non_empty)))
                : null;

            $columns[] = [
                'index' => $index,
                'label' => $label,
                'sample_values' => array_map(
                    fn (string $value) => Str::limit(str_replace(["\r\n", "\r", "\n"], ' · ', $value), 120),
                    array_slice(array_values(array_unique($non_empty)), 0, 4),
                ),
                'suggested_type' => $type,
                'detection_reason' => $reason,
                'compatible_types' => $compatible,
                'combined_from' => $combined_from[$index] ?? [],
                'filled_count' => $filled_rows,
                'distinct_count' => count(array_unique(array_map('mb_strtolower', $non_empty))),
                'declared_options' => array_map(
                    fn (array $option) => ['label' => $option['label'], 'color' => $option['color']],
                    $this->buildOptions($this->uniqueLabels($declared), $preset),
                ),
                'option_preset' => $preset,
                'assets' => $asset_cell_count === 0 ? null : $this->summarizeAssets($assets['files'], $asset_cell_count),
                'people_match' => in_array(BoardColumn::TYPE_PEOPLE, [$type, ...$compatible], true) ? $this->summarizePeopleMatch($non_empty, $users) : null,
            ];
        }

        return $columns;
    }

    /**
     * @param  array<int, array<int, array<int, StagedAsset>>>  $cell_assets
     * @return array<int, array{cells: array<int, true>, files: array<int, StagedAsset>}>
     */
    private function assetsByColumn(array $cell_assets): array
    {
        $by_column = [];

        foreach ($cell_assets as $row_index => $row_assets) {
            foreach ($row_assets as $column_index => $assets) {
                $by_column[$column_index]['cells'][(int) $row_index] = true;
                $by_column[$column_index]['files'] = [...($by_column[$column_index]['files'] ?? []), ...$assets];
            }
        }

        return $by_column;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, true>
     */
    private function filledRowIndexes(array $rows, int $index): array
    {
        $filled = [];

        foreach ($rows as $row_index => $row) {
            if (($row[$index] ?? '') !== '') {
                $filled[$row_index] = true;
            }
        }

        return $filled;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function countLinks(array $values): int
    {
        return count(array_filter($values, fn (string $value) => $this->parseLinks($value) !== null));
    }

    /**
     * "14 embedded images and 2 links", the reason a column became a Files column.
     *
     * @param  array<int, StagedAsset>  $files
     */
    private function describeAssets(array $files, int $link_count): string
    {
        $images = count(array_filter($files, fn (array $file) => $file['kind'] === 'image'));
        $documents = count($files) - $images;
        $parts = [];

        if ($images > 0) {
            $parts[] = $images.' embedded '.Str::plural('image', $images);
        }
        if ($documents > 0) {
            $parts[] = $documents.' embedded '.Str::plural('file', $documents);
        }
        if ($link_count > 0) {
            $parts[] = $link_count.' '.Str::plural('link', $link_count);
        }

        return ucfirst(implode(' and ', $parts)).', uploaded into a Files column';
    }

    /**
     * @param  array<int, StagedAsset>  $files
     * @return array{file_count: int, image_count: int, cell_count: int, total_bytes: int, previews: array<int, array{file_name: string, url: string|null, mime_type: string, kind: string, width: int|null, height: int|null}>}
     */
    private function summarizeAssets(array $files, int $cell_count): array
    {
        return [
            'file_count' => count($files),
            'image_count' => count(array_filter($files, fn (array $file) => $file['kind'] === 'image')),
            'cell_count' => $cell_count,
            'total_bytes' => array_sum(array_column($files, 'size_bytes')),
            'previews' => array_map(fn (array $file) => [
                'file_name' => $file['file_name'],
                'url' => $file['url'],
                'mime_type' => $file['mime_type'],
                'kind' => $file['kind'],
                'width' => $file['width'],
                'height' => $file['height'],
            ], array_slice($files, 0, self::ASSET_PREVIEW_LIMIT)),
        ];
    }

    /**
     * How many of the names under a column match a workspace user, and a few
     * that don't, so the wizard can say who would be left out of a People
     * column before importing.
     *
     * @param  array<int, string>  $values
     * @param  Collection<int, User>  $users
     * @return array{matched_count: int, total_count: int, unmatched: array<int, string>}
     */
    private function summarizePeopleMatch(array $values, Collection $users): array
    {
        $names = [];
        foreach ($values as $value) {
            foreach ($this->splitList($value) as $name) {
                $names[mb_strtolower($name)] ??= $name;
            }
        }

        $unmatched = array_values(array_filter($names, fn (string $name) => $this->matchUser($name, $users) === null));

        return [
            'matched_count' => count($names) - count($unmatched),
            'total_count' => count($names),
            'unmatched' => array_slice($unmatched, 0, 6),
        ];
    }

    /** Headers that name the item itself, picked as the item name whatever their position. */
    private const NAME_LABELS = ['name', 'item', 'item name', 'title', 'task', 'task name', 'subject', 'summary', 'issue', 'ticket'];

    /** Header words that hint a column describes the item, ranked up as the item name. */
    private const NAME_HINT_WORDS = ['name', 'title', 'task', 'item', 'subject', 'summary', 'description', 'feedback', 'issue', 'request', 'idea', 'goal', 'project', 'topic', 'question'];

    /**
     * The source column that best names each item. A header like "Name" or
     * "Title" wins outright. Otherwise every free text column is scored: how
     * full and how unique its values are (an item name rarely repeats), a
     * header hinting a name or description, and a little for being further
     * left. A column typed as a status, date, number, person, list or file is
     * never picked, so a sheet starting with a Status column still names its
     * items by the text column that describes them. Falls back to the first
     * column.
     *
     * @param  array<int, SourceColumn>  $source_columns
     */
    public function suggestNameIndex(array $source_columns): int
    {
        foreach ($source_columns as $column) {
            if (in_array(mb_strtolower(trim($column['label'])), self::NAME_LABELS, true)) {
                return $column['index'];
            }
        }

        $best_index = null;
        $best_score = 0.0;
        $max_filled = max(1, ...array_map(fn (array $column) => (int) $column['filled_count'], $source_columns ?: [['filled_count' => 1]]));

        foreach ($source_columns as $position => $column) {
            $is_text = in_array($column['suggested_type'], [BoardColumn::TYPE_TEXT, BoardColumn::TYPE_LONG_TEXT], true);

            if (! $is_text || $column['filled_count'] === 0 || $column['declared_options'] !== [] || $column['assets'] !== null) {
                continue;
            }

            $label = mb_strtolower($column['label']);
            $hinted = array_filter(self::NAME_HINT_WORDS, fn (string $word) => preg_match('/\b'.preg_quote($word, '/').'\b/u', $label) === 1) !== [];
            $uniqueness = $column['distinct_count'] / max(1, $column['filled_count']);

            $score = 3 * ($column['filled_count'] / $max_filled)
                + 2 * $uniqueness
                + ($hinted ? 2 : 0)
                + ($column['suggested_type'] === BoardColumn::TYPE_TEXT ? 0.5 : 0)
                - 0.05 * $position;

            if ($score > $best_score) {
                $best_score = $score;
                $best_index = $column['index'];
            }
        }

        return $best_index ?? ($source_columns[0]['index'] ?? 0);
    }

    /**
     * Pre-fills the "Map columns" step so every source column already has a
     * destination and the user never has to hand-pick one: the column that
     * best names the item (see {@see suggestNameIndex()}, not necessarily the
     * first one) is mapped to the item's built-in name field, any other column whose label case-insensitively matches an
     * existing board column is mapped straight to it, and every remaining
     * column defaults to `"create"` — a brand-new column typed from
     * `buildSourceColumns()`'s own value-based guess (`suggested_type`) —
     * rather than being left unmapped. The user can still override any row
     * to "Don't import" themselves; nothing here is forced.
     *
     * @param  array<int, SourceColumn>  $source_columns  {@see buildSourceColumns()}'s output, already carrying each column's guessed type
     * @param  Collection<int, BoardColumn>  $existing_columns
     * @return array<int, array{source_index: int, mode: string, target_column_id: int|null, new_label: string|null, new_type: string|null}>
     */
    public function suggestMappings(array $source_columns, Collection $existing_columns): array
    {
        $name_index = $this->suggestNameIndex($source_columns);

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
     * @param  array<string, mixed>  $parsed  {@see readSpreadsheet()}'s output
     */
    public function storeParsedImport(string $file_name, array $parsed): string
    {
        return $this->stageParsedImport($file_name, $parsed)['token'];
    }

    /**
     * {@see storeParsedImport()}, also handing back the cached payload. The
     * embedded files are written to the app disk (the one Files cells live
     * on, so a queue worker on another machine reaches them too) under
     * `board-imports/{token}/`, and the payload keeps each file's staged
     * path and URL instead of its bytes. The URL lets the wizard preview
     * the pictures before anything is imported.
     *
     * @param  array<string, mixed>  $parsed  {@see readSpreadsheet()}'s output
     * @return array{token: string, payload: array{file_name: string, headers: array<int, string>, rows: array<int, array<int, string>>, row_groups: array<int, string>, subitem_columns: array<int, array{key: string, label: string}>, subitems: array<int, array<int, array{name: string, data: array<string, string>}>>, sheet_index: int, column_options: array<int, array<int, string>>, cell_assets: array<int, array<int, array<int, StagedAsset>>>, asset_token: string|null, created_at: string}}
     */
    public function stageParsedImport(string $file_name, array $parsed): array
    {
        $this->pruneExpiredImports();

        $token = (string) Str::uuid();
        $cell_assets = $this->stageAssets($token, $parsed['cell_assets'] ?? []);

        $payload = [
            'file_name' => $file_name,
            'headers' => $parsed['headers'],
            'rows' => $parsed['rows'],
            'row_groups' => $parsed['row_groups'] ?? [],
            'subitem_columns' => $parsed['subitem_columns'] ?? [],
            'subitems' => $parsed['subitems'] ?? [],
            'sheet_index' => $parsed['sheet_index'] ?? 0,
            'column_options' => $parsed['column_options'] ?? [],
            'cell_assets' => $cell_assets,
            'asset_token' => $cell_assets === [] ? null : $token,
            'created_at' => now()->toIso8601String(),
        ];

        Storage::disk('local')->put($this->tokenPath($token), json_encode($payload, JSON_THROW_ON_ERROR));

        return ['token' => $token, 'payload' => $payload];
    }

    /**
     * Writes every embedded file to the staging folder and swaps its bytes
     * for where it now lives. A file that can't be written is left out.
     *
     * @param  array<int, array<int, array<int, array<string, mixed>>>>  $cell_assets
     * @return array<int, array<int, array<int, StagedAsset>>>
     */
    private function stageAssets(string $token, array $cell_assets): array
    {
        $disk = Storage::disk(config('filesystems.app_disk'));
        $staged = [];

        foreach ($cell_assets as $row_index => $row_assets) {
            foreach ($row_assets as $column_index => $assets) {
                foreach ($assets as $asset) {
                    if (! isset($asset['contents']) || ! is_string($asset['contents'])) {
                        continue;
                    }

                    $id = (string) Str::uuid();
                    $extension = strtolower(pathinfo((string) $asset['file_name'], PATHINFO_EXTENSION)) ?: 'bin';
                    $path = self::ASSET_STAGING_DIR."/{$token}/{$id}.{$extension}";

                    try {
                        if (! $disk->put($path, $asset['contents'])) {
                            continue;
                        }
                    } catch (Throwable) {
                        continue;
                    }

                    $size = $asset['kind'] === 'image' ? @getimagesizefromstring($asset['contents']) : false;

                    $staged[$row_index][$column_index][] = [
                        'id' => $id,
                        'file_name' => (string) $asset['file_name'],
                        'mime_type' => (string) $asset['mime_type'],
                        'kind' => (string) $asset['kind'],
                        'size_bytes' => strlen($asset['contents']),
                        'width' => is_array($size) ? (int) $size[0] : null,
                        'height' => is_array($size) ? (int) $size[1] : null,
                        'staged_path' => $path,
                        'url' => $disk->url($path),
                    ];
                }
            }
        }

        return $staged;
    }

    /**
     * Deletes the embedded files an import staged, once its job no longer
     * needs them (finished, failed or cancelled) or the wizard moved on to
     * another sheet.
     *
     * @param  array<string, mixed>|null  $parsed
     */
    public function discardStagedAssets(?array $parsed): void
    {
        $token = is_array($parsed) ? ($parsed['asset_token'] ?? null) : null;

        if (is_string($token) && $this->isValidToken($token)) {
            Storage::disk(config('filesystems.app_disk'))->deleteDirectory(self::ASSET_STAGING_DIR."/{$token}");
        }
    }

    /**
     * Drops a cached upload the wizard abandoned (the user switched sheets or
     * picked another file), with its staged files.
     */
    public function discardParsedImport(string $token): void
    {
        $this->discardStagedAssets($this->loadParsedImport($token));
        $this->deleteParsedImport($token);
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

        // The monday.com structure keys and the spreadsheet extras are optional, a payload cached before they existed has none.
        foreach (['row_groups', 'subitem_columns', 'subitems', 'column_options', 'cell_assets'] as $key) {
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
     * @param  array{headers: array<int, string>, rows: array<int, array<int, string>>, row_groups?: array<int, string>, subitem_columns?: array<int, array{key: string, label: string}>, subitems?: array<int, array<int, array{name: string, data: array<string, string>}>>, column_options?: array<int, array<int, string>>, cell_assets?: array<int, array<int, array<int, StagedAsset>>>, asset_token?: string|null}  $parsed
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
        [$name_index, $column_targets, $columns_created] = DB::transaction(
            fn () => $this->resolveMappings($board, $view, $headers, $rows, $options['mappings'], $parsed['column_options'] ?? [])
        );
        $cell_assets = $parsed['cell_assets'] ?? [];
        $image_variants = app(CellImageVariantService::class);

        $users = User::all();
        [$subitem_definitions, $subitem_columns, $subitem_columns_created] = $subitems_by_row === []
            ? [[], [], 0]
            : DB::transaction(fn () => $this->resolveSubitemColumns($board, $view, $parsed['subitem_columns'] ?? [], $subitems_by_row, $users));
        $columns_created += $subitem_columns_created;

        $duplicate_mode = $options['duplicate_mode'];
        $match_index = $options['match_source_index'] ?? $name_index;
        $lookup_group_ids = $uses_file_groups ? $view->groups()->pluck('id')->all() : [$default_group->id];
        $existing_by_key = $duplicate_mode !== 'add'
            ? $this->buildExistingItemLookup($board, $lookup_group_ids, $match_index, $name_index, $column_targets)
            : [];

        $caster = new ImportedCellValueCaster($board, $users);
        $item_id_column_ids = $this->itemIdColumnIds($view, BoardColumn::SCOPE_ITEM);
        $subitem_id_column_ids = $subitems_by_row === [] ? [] : $this->itemIdColumnIds($view, BoardColumn::SCOPE_SUBITEM);
        $date_auto_filler = app(BoardDateAutoFiller::class);
        $auto_fill_columns = $date_auto_filler->autoFillColumns($view->id, BoardColumn::SCOPE_ITEM)->all();
        $subitem_auto_fill_columns = $subitems_by_row === [] ? [] : $date_auto_filler->autoFillColumns($view->id, BoardColumn::SCOPE_SUBITEM)->all();
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
                $chunk, $board, $row_groups, $groups_by_name, $default_group, $name_index, $column_targets, $duplicate_mode, $match_index,
                &$existing_by_key, $caster, $item_id_column_ids, $subitem_id_column_ids, $subitems_by_row, $subitem_definitions, $subitem_columns,
                $date_auto_filler, $auto_fill_columns, $subitem_auto_fill_columns, $cell_assets, $image_variants,
                &$created, &$updated, &$skipped, &$subitems_created, &$next_positions,
            ) {
                foreach ($chunk as $row_index => $row) {
                    $name_raw = trim($row[$name_index] ?? '');
                    [$name, $overflow] = $this->splitOverflowingName($name_raw !== '' ? $name_raw : 'Untitled item');

                    [$values, $linked_cells] = $this->castRowValues($column_targets, $row, $caster);

                    $match_raw = $match_index === $name_index ? $name_raw : trim($row[$match_index] ?? '');
                    $match_key = $this->normalizeForMatch($match_raw);
                    $existing_item = $match_key !== '' ? ($existing_by_key[$match_key] ?? null) : null;

                    if ($existing_item !== null && $duplicate_mode === 'skip') {
                        $skipped++;

                        continue;
                    }

                    if ($existing_item !== null && $duplicate_mode === 'update') {
                        $existing_item->fill(['name' => $name, 'description' => $overflow])->save();
                        $values = $this->withEmbeddedFiles($values, $cell_assets[$row_index] ?? [], $column_targets, $existing_item->id, $image_variants);

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

                    $values = $this->withEmbeddedFiles($values, $cell_assets[$row_index] ?? [], $column_targets, $item->id, $image_variants);

                    // Like monday.com, an item's Item ID is the item's own id.
                    foreach ($item_id_column_ids as $column_id) {
                        $values[] = ['column_id' => $column_id, 'value' => $item->id];
                    }

                    $values = $this->withAutoFilledDates($values, $item, $auto_fill_columns, $date_auto_filler);

                    if ($values !== []) {
                        $item->values()->createMany($values);
                    }

                    foreach ($linked_cells as [$column, $raw]) {
                        $caster->deferLinkedItems($item->id, $column, $raw);
                    }

                    // A new item is also the only one its subitems are added to: an updated
                    // item keeps the subitems it already has rather than getting a second copy.
                    foreach (array_values($subitems_by_row[$row_index] ?? []) as $position => $subitem_row) {
                        $this->createSubitem($board, $item, $position, $subitem_row, $subitem_definitions, $subitem_columns, $subitem_id_column_ids, $caster, $subitem_auto_fill_columns, $date_auto_filler);
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

        // Every embedded file was copied to its item, the staged copies are no longer needed.
        $this->discardStagedAssets($parsed);

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
     * Row 1 is the header, every later row with something in it is a data
     * row. A row holding nothing but unticked checkboxes (FALSE) is left out,
     * since a sheet often pre-fills a whole checkbox column far below its
     * data, and a row whose only content is an embedded file is kept. A
     * column with no header, no value and no file is dropped. `letters` and
     * `row_numbers` remember where each kept column and row sat in the sheet.
     *
     * @param  array<string, array<int, mixed>>  $assets_by_coordinate
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, letters: array<int, string>, row_numbers: array<int, int>}
     */
    private function readFlatSheet(Worksheet $sheet, array $assets_by_coordinate = []): array
    {
        $highest_row = $sheet->getHighestDataRow();
        $highest_col_index = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $asset_columns = [];

        foreach (array_keys($assets_by_coordinate) as $coordinate) {
            [$letter, $row_number] = Coordinate::coordinateFromString($coordinate);
            $asset_columns[$letter] = true;
            $highest_row = max($highest_row, (int) $row_number);
            $highest_col_index = max($highest_col_index, Coordinate::columnIndexFromString($letter));
        }

        $letters = [];
        $headers = [];
        for ($c = 1; $c <= $highest_col_index; $c++) {
            $letters[] = Coordinate::stringFromColumnIndex($c);
            $headers[] = $this->cell($sheet, $letters[$c - 1], 1);
        }

        $rows = [];
        $row_numbers = [];
        $filled_columns = [];

        for ($row = 2; $row <= $highest_row; $row++) {
            $values = [];
            $has_value = false;

            foreach ($letters as $index => $letter) {
                $value = $this->cell($sheet, $letter, $row);

                if (isset($assets_by_coordinate[$letter.$row])) {
                    $has_value = true;
                }

                if ($value !== '' && $sheet->getCell($letter.$row)->getValue() !== false) {
                    $has_value = true;
                }

                $values[] = $value;
            }

            if ($has_value) {
                $rows[] = $values;
                $row_numbers[] = $row;

                foreach ($values as $index => $value) {
                    if ($value !== '' && $value !== 'FALSE') {
                        $filled_columns[$index] = true;
                    }
                }
            }
        }

        $kept = array_values(array_filter(
            array_keys($headers),
            fn (int $index) => $headers[$index] !== '' || isset($filled_columns[$index]) || isset($asset_columns[$letters[$index]]),
        ));

        return [
            'headers' => array_map(fn (int $index) => $headers[$index] !== '' ? $headers[$index] : 'Column '.$letters[$index], $kept),
            'rows' => array_map(fn (array $values) => array_map(fn (int $index) => $values[$index], $kept), $rows),
            'letters' => array_map(fn (int $index) => $letters[$index], $kept),
            'row_numbers' => $row_numbers,
        ];
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

        $this->pruneExpiredStagedAssets($cutoff);
    }

    /** Staged embedded files of an upload nobody imported (the wizard was closed) are removed after the same delay. */
    private function pruneExpiredStagedAssets(int $cutoff): void
    {
        $disk = Storage::disk(config('filesystems.app_disk'));

        try {
            foreach ($disk->directories(self::ASSET_STAGING_DIR) as $directory) {
                $files = $disk->files($directory);

                if ($files === [] || $disk->lastModified($files[0]) < $cutoff) {
                    $disk->deleteDirectory($directory);
                }
            }
        } catch (Throwable) {
            // Pruning is housekeeping, it never blocks a new import.
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
     * @param  array<int, BoardColumn>  $auto_fill_columns
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
        array $auto_fill_columns,
        BoardDateAutoFiller $date_auto_filler,
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

        $values = $this->withAutoFilledDates($values, $subitem, $auto_fill_columns, $date_auto_filler);

        if ($values !== []) {
            $subitem->values()->createMany($values);
        }
    }

    /**
     * Adds the files embedded in a row's cells to the Files columns those
     * cells are mapped to, after any links the cell's text already gave it.
     * Each staged file is copied into the item's own folder, exactly where an
     * upload from the cell lands, with the same thumbnail and preview
     * variants (see {@see CellImageVariantService}). Files of a cell mapped
     * to any other type, or not imported, are left out. A file column mapped
     * to several Files columns gives each one its own copy of every file.
     *
     * @param  array<int, array{column_id: int, value: mixed}>  $values
     * @param  array<int, array<int, StagedAsset>>  $row_assets  column index => staged files
     * @param  array<int, array{0: int, 1: BoardColumn}>  $column_targets
     * @return array<int, array{column_id: int, value: mixed}>
     */
    private function withEmbeddedFiles(array $values, array $row_assets, array $column_targets, int $item_id, CellImageVariantService $image_variants): array
    {
        foreach ($column_targets as [$column_index, $column]) {
            $assets = $row_assets[$column_index] ?? [];

            if ($assets === [] || $column->type !== BoardColumn::TYPE_FILES) {
                continue;
            }

            $entries = array_values(array_filter(array_map(
                fn (array $asset) => $this->promoteStagedAsset($asset, $item_id, $image_variants),
                $assets,
            )));

            if ($entries === []) {
                continue;
            }

            $position = array_search($column->id, array_column($values, 'column_id'), true);

            if ($position === false) {
                $values[] = ['column_id' => $column->id, 'value' => $entries];
            } else {
                $values[$position]['value'] = [...(is_array($values[$position]['value']) ? $values[$position]['value'] : []), ...$entries];
            }
        }

        return $values;
    }

    /**
     * Copies one staged file into `board-cell-files/{item_id}/` and returns
     * the Files cell entry for it, or null when the staged copy is gone.
     *
     * @param  StagedAsset  $asset
     * @return array<string, mixed>|null
     */
    private function promoteStagedAsset(array $asset, int $item_id, CellImageVariantService $image_variants): ?array
    {
        $disk = Storage::disk(config('filesystems.app_disk'));
        $staged_path = $asset['staged_path'];

        if (! str_starts_with($staged_path, self::ASSET_STAGING_DIR.'/') || ! $disk->exists($staged_path)) {
            return null;
        }

        $directory = "board-cell-files/{$item_id}";
        $path = $directory.'/'.Str::uuid().'.'.pathinfo($staged_path, PATHINFO_EXTENSION);

        if (! $disk->copy($staged_path, $path)) {
            return null;
        }

        $mime_type = $asset['mime_type'];
        $entry = [
            'id' => (string) Str::uuid(),
            'kind' => BoardItemCellFileController::KIND_FILE,
            'file_name' => Str::limit($asset['file_name'], 255, ''),
            'path' => $path,
            'url' => $disk->url($path),
            'mime_type' => $mime_type,
            'size_bytes' => $asset['size_bytes'],
        ];

        if (! CellImageVariantService::supports($mime_type)) {
            return $entry;
        }

        // The variants are generated from a local copy, the app disk may be remote storage.
        $local_path = tempnam(sys_get_temp_dir(), 'import_asset_');

        try {
            file_put_contents($local_path, $disk->get($path));
            $variants = $image_variants->generate($local_path, $directory);
        } catch (Throwable) {
            $variants = null;
        } finally {
            @unlink($local_path);
        }

        return $variants ? [...$entry, ...$variants] : $entry;
    }

    /**
     * Adds the creation date for every auto fill Date column the imported row
     * left empty, see {@see BoardDateAutoFiller}. A date from the file wins.
     *
     * @param  array<int, array{column_id: int, value: mixed}>  $values
     * @param  array<int, BoardColumn>  $auto_fill_columns
     * @return array<int, array{column_id: int, value: mixed}>
     */
    private function withAutoFilledDates(array $values, BoardItem $item, array $auto_fill_columns, BoardDateAutoFiller $date_auto_filler): array
    {
        $filled_column_ids = array_column(
            array_filter($values, fn (array $entry) => ! BoardDateAutoFiller::isEmptyValue($entry['value'])),
            'column_id',
        );

        foreach ($auto_fill_columns as $column) {
            if (! in_array($column->id, $filled_column_ids, true)) {
                $values[] = ['column_id' => $column->id, 'value' => $date_auto_filler->valueFor($item, $column)];
            }
        }

        return $values;
    }

    /**
     * Applies every "Map columns" row: `"name"` picks which source column
     * feeds the item's built-in name field, `"map"` points a source column
     * at an existing board column, `"create"` makes a brand-new one (typed
     * from the values actually collected under it, mirroring
     * {@see MondayBoardImportService::inferColumns()}'s option-building), and
     * `"skip"` drops the column from the import entirely.
     *
     * The same source column may appear in several rows (the wizard's
     * "Duplicate" action), each one feeding its own board column, so the
     * result is a list of `[source index, column]` pairs in the order the
     * rows were given rather than a map keyed by source index. The name row
     * can also be duplicated into a regular column.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, array{source_index: int, mode: string, target_column_id: int|null, new_label: string|null, new_type: string|null}>  $mappings
     * @param  array<int, array<int, string>>  $column_options  the dropdown list each source column's cells picked from, see {@see readSpreadsheet()}
     * @return array{0: int, 1: array<int, array{0: int, 1: BoardColumn}>, 2: int}
     */
    private function resolveMappings(WorkspaceNavigationItem $board, BoardView $view, array $headers, array $rows, array $mappings, array $column_options = []): array
    {
        $name_index = null;
        $column_targets = [];
        $mapped_column_ids = [];
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

                // A board column holds one value per item, so only the first row mapped to it writes into it.
                if ($column !== null && ! isset($mapped_column_ids[$column->id])) {
                    $mapped_column_ids[$column->id] = true;
                    $column_targets[] = [$index, $column];
                }

                continue;
            }

            if ($mapping['mode'] === 'create') {
                $label = $this->truncate($mapping['new_label'] ?: $headers[$index]);
                $type = in_array($mapping['new_type'], $this->creatableColumnTypes(), true) ? $mapping['new_type'] : BoardColumn::TYPE_TEXT;
                $values = $this->columnValues($rows, $index);
                $option_labels = $this->optionLabelsFor($type, $values, $column_options[$index] ?? []);

                // A column picked from a dropdown list starts with every option of that list, in its order.
                if (in_array($type, [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DROPDOWN], true) && ($column_options[$index] ?? []) !== []) {
                    $option_labels = $this->uniqueLabels([...$column_options[$index], ...$option_labels]);
                }

                $preset = $option_labels === [] ? null : $this->optionPresetFor($headers[$index], $option_labels);

                $column = $view->columns()->create([
                    'board_id' => $board->id,
                    'key' => $this->uniqueColumnKey($view, $label, BoardColumn::SCOPE_ITEM),
                    'label' => $label,
                    'type' => $type,
                    'scope' => BoardColumn::SCOPE_ITEM,
                    'position' => $next_position++,
                    'width' => $this->widthForType($type),
                    'config' => $this->initialColumnConfig($type, $option_labels, $values, $preset),
                ]);

                $column_targets[] = [$index, $column];
                $columns_created++;
            }
        }

        return [$name_index ?? 0, $column_targets, $columns_created];
    }

    /**
     * Preloads the target table's existing rows into a lookup keyed by a
     * normalized "match" value — the row-by-row loop in `commit()` just
     * looks its own value up in this map instead of querying per row.
     * Returns empty when the dedupe column isn't the name field and wasn't
     * actually mapped to a real column (nothing to compare against).
     *
     * @param  array<int, int>  $group_ids  the tables whose items count as "already on the board"
     * @param  array<int, array{0: int, 1: BoardColumn}>  $column_targets
     * @return array<string, BoardItem>
     */
    private function buildExistingItemLookup(WorkspaceNavigationItem $board, array $group_ids, int $match_index, int $name_index, array $column_targets): array
    {
        // A duplicated source column compares against the first board column it was mapped to.
        $match_column = null;
        if ($match_index !== $name_index) {
            foreach ($column_targets as [$index, $column]) {
                if ($index === $match_index) {
                    $match_column = $column;

                    break;
                }
            }
        }

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
     * @param  array<int, array{0: int, 1: BoardColumn}>  $column_targets
     * @param  array<int, string>  $row
     * @return array{0: array<int, array{column_id: int, value: mixed}>, 1: array<int, array{0: BoardColumn, 1: string}>}
     */
    private function castRowValues(array $column_targets, array $row, ImportedCellValueCaster $caster): array
    {
        $values = [];
        $linked_cells = [];

        foreach ($column_targets as [$index, $column]) {
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
     *                                                `index_map` maps every old column index to the new one it ended up in.
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, combined_from: array<int, array<int, string>>, index_map: array<int, int>}
     */
    private function combineSplitColumns(array $headers, array $rows): array
    {
        $groups = $this->findSplitColumnGroups(array_map(
            fn (int $index) => ['label' => $headers[$index], 'values' => $this->columnValues($rows, $index)],
            array_keys($headers),
        ));

        if ($groups === []) {
            return ['headers' => $headers, 'rows' => $rows, 'combined_from' => [], 'index_map' => array_combine(array_keys($headers), array_keys($headers))];
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
        $index_map = [];
        /** @var array<int, array{type: string, label: string, members: array<int, int>, reason: string}|int> $layout each new column: a merged group, or the old index it copies */
        $layout = [];

        foreach ($headers as $index => $label) {
            if (isset($group_by_first_member[$index])) {
                $group = $group_by_first_member[$index];
                foreach ($group['members'] as $member) {
                    $index_map[$member] = count($new_headers);
                }
                $combined_from[count($new_headers)] = array_map(fn (int $member) => $headers[$member], $group['members']);
                $new_headers[] = $group['label'];
                $layout[] = $group;

                continue;
            }

            if (! isset($consumed[$index])) {
                $index_map[$index] = count($new_headers);
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

        return ['headers' => $new_headers, 'rows' => $new_rows, 'combined_from' => $combined_from, 'index_map' => $index_map];
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
     * comma-separated token for a Dropdown. A cell naming one of the file's
     * own dropdown options whole is never split, an option may hold a comma.
     *
     * @param  array<int, string>  $values  every non-empty raw cell collected under this column
     * @param  array<int, string>  $declared  the dropdown list the cells picked from, if any
     * @return array<int, string>
     */
    private function optionLabelsFor(string $type, array $values, array $declared = []): array
    {
        if ($type === BoardColumn::TYPE_DROPDOWN) {
            $declared_keys = array_flip(array_map(fn (string $label) => mb_strtolower(trim($label)), $declared));
            $tokens = [];
            foreach ($values as $value) {
                $choices = isset($declared_keys[mb_strtolower(trim($value))]) ? [trim($value)] : $this->splitList($value);
                foreach ($choices as $token) {
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
