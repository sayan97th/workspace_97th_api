<?php

namespace App\Concerns;

use App\Services\Board\BoardItemImportService;
use App\Services\Board\MondayBoardImportService;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

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
    /**
     * A cell's text as the sheet displays it, with two normalizations the
     * display text alone would lose: a real Excel date cell comes back as
     * `Y-m-d` (or `Y-m-d H:i` with a time of day) regardless of the locale
     * format it's displayed in — so "1/5/2022" can never be misread as
     * May 1st — and a hyperlinked cell whose text isn't the URL itself comes
     * back in monday.com's own "Display text - https://..." link layout.
     */
    private function cell(Worksheet $sheet, string $column, int $row): string
    {
        $cell = $sheet->getCell($column.$row);
        $raw = $cell->getValue();

        if ((is_int($raw) || is_float($raw)) && SpreadsheetDate::isDateTime($cell)) {
            try {
                $date = SpreadsheetDate::excelToDateTimeObject($raw);

                return $date->format('H:i') === '00:00' ? $date->format('Y-m-d') : $date->format('Y-m-d H:i');
            } catch (Throwable) {
                // Not a representable date after all — fall back to the displayed text.
            }
        }

        $value = trim((string) $cell->getFormattedValue());

        if ($value !== '' && $cell->hasHyperlink()) {
            $url = trim($cell->getHyperlink()->getUrl());

            if (preg_match('#^https?://#i', $url) === 1 && ! str_contains($value, $url)) {
                return "{$value} - {$url}";
            }
        }

        return $value;
    }

    /**
     * monday.com's own item/subitem ids are long numbers. Requiring that
     * length (rather than any digits at all) is what keeps a group's summary
     * row or an item whose "Hours" cell happens to sit in the subitem id
     * column from being mistaken for a real row.
     */
    private function isMondayId(string $value): bool
    {
        return preg_match('/^\d{6,}$/', $value) === 1;
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

    /**
     * Reads a header row into an ordered column list, keyed by a slug of its
     * own label — e.g. a "Due Date" header becomes `['letter' => 'D', 'label'
     * => 'Due Date', 'key' => 'due_date']`. `$name_col` (the item name
     * column) and `$id_col` (the trailing auto-generated "Item ID" column)
     * are excluded from the list, since neither is a real mappable column,
     * and so is any column labeled "Subitems": either the literal marker
     * cell that opens a subitems header, or monday.com's auto-generated
     * rollup of an item's subitem names, redundant with the real subitems.
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
