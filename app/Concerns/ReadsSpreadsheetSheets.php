<?php

namespace App\Concerns;

use App\Services\Board\BoardItemImportService;
use App\Services\Board\MondayBoardImportService;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Low-level `PhpOffice\PhpSpreadsheet` cell-reading helpers, plus monday.com
 * export header-row detection — mirrors the private helpers
 * {@see MondayBoardImportService} already uses to walk a
 * board export (a title row, an optional description row, one grey-shaded
 * "Name | ... | Item ID" header row per group), so {@see BoardItemImportService}
 * can recognize the same skeleton when a user drags in a raw monday.com
 * export through the "Import items" wizard instead of a plain flat table.
 */
trait ReadsSpreadsheetSheets
{
    private function cell(Worksheet $sheet, string $column, int $row): string
    {
        return trim((string) $sheet->getCell($column.$row)->getFormattedValue());
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

    private function lastNonEmptyCellValue(Worksheet $sheet, int $row): string
    {
        $column = $this->lastNonEmptyColumn($sheet, $row);

        return $column === null ? '' : $this->cell($sheet, $column, $row);
    }

    /**
     * True when `$row` is a monday.com export's own "Name | ... | Item ID
     * (auto generated)" header row — the literal text its exporter always
     * uses, regardless of which columns sit between those two.
     */
    private function isItemHeaderRow(Worksheet $sheet, int $row): bool
    {
        if ($this->cell($sheet, 'A', $row) !== 'Name') {
            return false;
        }

        return $this->isItemIdLabel($this->lastNonEmptyCellValue($sheet, $row));
    }

    private function isItemIdLabel(string $label): bool
    {
        return str_starts_with(mb_strtolower($label), 'item id');
    }

    /**
     * Reads a header row into an ordered column list, keyed by a slug of its
     * own label — e.g. a "Due Date" header becomes `['letter' => 'D', 'label'
     * => 'Due Date', 'key' => 'due_date']`. `$name_col` (the item name
     * column) and `$id_col` (the trailing auto-generated "Item ID" column)
     * are excluded from the list, since neither is a real mappable column.
     *
     * @return array{columns: array<int, array{letter: string, label: string, key: string}>, id_col: string}
     */
    private function readHeader(Worksheet $sheet, int $row, string $name_col): array
    {
        $id_col = $this->lastNonEmptyColumn($sheet, $row) ?? $name_col;
        $highest_index = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        $columns = [];

        for ($i = 1; $i <= $highest_index; $i++) {
            $letter = Coordinate::stringFromColumnIndex($i);

            if ($letter === $name_col || $letter === $id_col) {
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
     * A stable, unique-within-this-header machine key for a column label,
     * e.g. "Due Date" -> `due_date`. Collisions (two columns literally both
     * named "Task") get a numeric suffix.
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
}
