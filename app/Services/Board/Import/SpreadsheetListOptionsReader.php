<?php

namespace App\Services\Board\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads the dropdown lists (Data > Data validation > List) a sheet's columns
 * offer, so an imported Status/Dropdown column starts with every choice the
 * file allowed, in the file's own order, not only the ones some row happened
 * to use. Handles the three ways a list is written: an inline
 * `"Open,Done,Stuck"`, a cell range (on the same or another sheet) and a
 * defined name pointing at a range.
 */
class SpreadsheetListOptionsReader
{
    /** A list longer than this is a lookup table, not a set of labels. */
    private const MAX_OPTIONS = 60;

    /** A label longer than this is a sentence, not a choice. */
    private const MAX_OPTION_LENGTH = 255;

    /**
     * Column letter => the list options its data rows (below `$header_row`)
     * offer. Several lists on one column (one range per status variant, as in
     * a sheet edited over time) are merged in order, deduplicated
     * case-insensitively.
     *
     * @return array<string, array<int, string>>
     */
    public function read(Worksheet $sheet, int $header_row = 1): array
    {
        $options_by_column = [];

        foreach ($sheet->getDataValidationCollection() as $range => $validation) {
            if ($validation->getType() !== DataValidation::TYPE_LIST) {
                continue;
            }

            $options = $this->listOptions($sheet, $validation->getFormula1());

            if ($options === []) {
                continue;
            }

            foreach ($this->columnsBelowHeader((string) $range, $header_row) as $column) {
                $options_by_column[$column] = $this->mergeOptions($options_by_column[$column] ?? [], $options);
            }
        }

        return $options_by_column;
    }

    /**
     * Column letters a validation range covers below the header row.
     *
     * @return array<int, string>
     */
    private function columnsBelowHeader(string $range, int $header_row): array
    {
        $columns = [];

        foreach (preg_split('/[\s,]+/', trim($range)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }

            try {
                [[$first_column, $first_row], [$last_column, $last_row]] = Coordinate::rangeBoundaries(str_replace('$', '', $part));
            } catch (Throwable) {
                continue;
            }

            if ((int) $last_row <= $header_row && (int) $first_row !== 0) {
                continue;
            }

            for ($index = (int) $first_column; $index <= (int) $last_column && $index - (int) $first_column < 200; $index++) {
                $columns[] = Coordinate::stringFromColumnIndex($index);
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * @return array<int, string>
     */
    private function listOptions(Worksheet $sheet, string $formula): array
    {
        $formula = trim($formula);

        if ($formula === '') {
            return [];
        }

        // An inline list: "Open,In Progress,Done".
        if (str_starts_with($formula, '"') && str_ends_with($formula, '"')) {
            return $this->clean(explode(',', str_replace('""', '"', substr($formula, 1, -1))));
        }

        $reference = ltrim($formula, '=');

        // A defined name ("StatusList") pointing at a range.
        if (preg_match('/^[A-Za-z_\\\\][\w.]*$/', $reference) === 1 && preg_match('/^[A-Z]{1,3}\d+$/i', $reference) !== 1) {
            $named_range = $sheet->getParent()?->getNamedRange($reference, $sheet);

            if ($named_range === null) {
                return [];
            }

            $reference = $named_range->getValue();
        }

        return $this->rangeValues($sheet, $reference);
    }

    /**
     * The values of a range like `$Z$1:$Z$5` or `'Lists'!$A$2:$A$9`.
     *
     * @return array<int, string>
     */
    private function rangeValues(Worksheet $sheet, string $reference): array
    {
        $target_sheet = $sheet;
        $range = $reference;

        if (str_contains($reference, '!')) {
            [$sheet_name, $range] = explode('!', $reference, 2);
            $target_sheet = $sheet->getParent()?->getSheetByName(trim($sheet_name, "'"));
        }

        if ($target_sheet === null) {
            return [];
        }

        $range = str_replace('$', '', $range);

        if (preg_match('/^[A-Z]{1,3}\d+(:[A-Z]{1,3}\d+)?$/i', $range) !== 1) {
            return [];
        }

        try {
            $values = $target_sheet->rangeToArray($range, null, true, true, false);
        } catch (Throwable) {
            return [];
        }

        return $this->clean(array_merge(...array_map(fn (array $row) => array_values($row), $values)));
    }

    /**
     * Trimmed, non-empty, deduplicated (case-insensitively, first spelling
     * wins) and capped.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function clean(array $values): array
    {
        $options = [];

        foreach ($values as $value) {
            $label = trim((string) $value);

            if ($label === '' || mb_strlen($label) > self::MAX_OPTION_LENGTH) {
                continue;
            }

            $options[mb_strtolower($label)] ??= $label;
        }

        return array_slice(array_values($options), 0, self::MAX_OPTIONS);
    }

    /**
     * @param  array<int, string>  $current
     * @param  array<int, string>  $incoming
     * @return array<int, string>
     */
    private function mergeOptions(array $current, array $incoming): array
    {
        return $this->clean([...$current, ...$incoming]);
    }
}
