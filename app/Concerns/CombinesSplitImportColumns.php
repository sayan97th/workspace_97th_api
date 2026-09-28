<?php

namespace App\Concerns;

use App\Models\BoardColumn;
use App\Services\Board\BoardItemImportService;
use App\Services\Board\MondayBoardImportService;
use Illuminate\Support\Str;

/**
 * Finds the source columns a spreadsheet splits one board value across, and
 * merges their cells back into a single raw cell that
 * {@see InfersBoardColumnTypes} and {@see ImportedCellValueCaster} already
 * know how to read. Shared by the `board:import-monday*` commands
 * ({@see MondayBoardImportService}) and the "Import items" wizard
 * ({@see BoardItemImportService}), so both collapse the same columns.
 *
 * Two layouts are recognized:
 *
 * - A Timeline split into a "Timeline - Start" / "Timeline - End" pair (any
 *   prefix works, e.g. "Date - Start" / "Date - End"), merged into one
 *   "2024-01-01 - 2024-01-31" range cell.
 * - A checklist spread over repeated "text | checkmark" pairs, e.g.
 *   "Task | Status | Task | Status | Task | Checkbox", merged into one
 *   "[x] Contract signed\n[ ] Kickoff scheduled" cell. A single pair is
 *   never merged: it takes at least two pairs sharing the same text label
 *   to tell a checklist apart from an ordinary "Task" column that happens
 *   to sit next to a checkbox.
 *
 * Requires the using class to also use {@see InfersBoardColumnTypes}, whose
 * checkmark token helpers this reads.
 */
trait CombinesSplitImportColumns
{
    /** Header labels a checklist's "done" column may carry when it has no values to go on. */
    private const CHECKMARK_HEADER_PATTERN = '/^(checkbox|status|done|complete|completed|check)(\s*\d+)?$/i';

    /**
     * @param  array<int, array{label: string, values: array<int, string>}>  $columns  positional, in sheet order; `values` holds every non-empty cell under the column
     * @return array<int, array{type: string, label: string, members: array<int, int>, reason: string}> `members` are positions in `$columns`; a checklist lists them as text/checkmark pairs
     */
    private function findSplitColumnGroups(array $columns): array
    {
        $groups = [];
        $consumed = [];

        foreach ($columns as $position => $column) {
            if (preg_match('/^(.*?)\s*-\s*start$/i', trim($column['label']), $matches) !== 1) {
                continue;
            }

            $prefix = trim($matches[1]);
            $end_label = ($prefix !== '' ? $prefix.' ' : '').'- End';
            $end_position = $this->findColumnPosition($columns, $end_label, $consumed);

            if ($end_position === null) {
                continue;
            }

            $groups[] = [
                'type' => BoardColumn::TYPE_TIMELINE,
                'label' => $prefix !== '' ? $prefix : 'Timeline',
                'members' => [$position, $end_position],
                'reason' => "Built from the \"{$column['label']}\" / \"{$columns[$end_position]['label']}\" pair",
            ];
            $consumed[$position] = true;
            $consumed[$end_position] = true;
        }

        /** @var array<string, array<int, array{0: int, 1: int}>> $pairs_by_label */
        $pairs_by_label = [];
        $positions = array_keys($columns);

        foreach ($positions as $offset => $position) {
            $next_position = $positions[$offset + 1] ?? null;

            if ($next_position === null || isset($consumed[$position]) || isset($consumed[$next_position])) {
                continue;
            }

            if ($this->isCheckmarkColumn($columns[$position]) || ! $this->isCheckmarkColumn($columns[$next_position])) {
                continue;
            }

            // A checkmark column never opens a pair, so pairs found here can't overlap.
            $pairs_by_label[$this->checklistBaseLabel($columns[$position]['label'])][] = [$position, $next_position];
        }

        foreach ($pairs_by_label as $pairs) {
            if (count($pairs) < 2) {
                continue;
            }

            $members = [];
            foreach ($pairs as [$text_position, $check_position]) {
                $members[] = $text_position;
                $members[] = $check_position;
                $consumed[$text_position] = true;
                $consumed[$check_position] = true;
            }

            $text_label = trim($columns[$pairs[0][0]]['label']);

            $groups[] = [
                'type' => BoardColumn::TYPE_CHECKLIST,
                'label' => Str::plural($text_label),
                'members' => $members,
                'reason' => sprintf('Built from %d repeated "%s" and checkmark column pairs', count($pairs), $text_label),
            ];
        }

        usort($groups, fn (array $a, array $b) => $a['members'][0] <=> $b['members'][0]);

        return $groups;
    }

    /**
     * Merges one row's member cells (in `members` order) into the single raw
     * cell the merged column reads.
     *
     * @param  array<int, string>  $cells
     */
    private function combineSplitCells(string $type, array $cells): string
    {
        $cells = array_map(fn ($cell) => trim((string) $cell), array_values($cells));

        if ($type === BoardColumn::TYPE_TIMELINE) {
            [$start, $end] = [$cells[0] ?? '', $cells[1] ?? ''];

            if ($start === '' && $end === '') {
                return '';
            }

            // monday.com lets a Timeline hold just one side: keep that day as a one-day range.
            return ($start !== '' ? $start : $end).' - '.($end !== '' ? $end : $start);
        }

        $lines = [];

        foreach (array_chunk($cells, 2) as $pair) {
            $text = $pair[0];

            if ($text === '') {
                continue;
            }

            $lines[] = ($this->isCheckedToken($pair[1] ?? '') ? '[x] ' : '[ ] ').str_replace(["\r\n", "\r", "\n"], ' ', $text);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{label: string, values: array<int, string>}  $column
     */
    private function isCheckmarkColumn(array $column): bool
    {
        if ($column['values'] === []) {
            return preg_match(self::CHECKMARK_HEADER_PATTERN, trim($column['label'])) === 1;
        }

        foreach ($column['values'] as $value) {
            if (! $this->isCheckedToken($value) && ! $this->isUncheckedToken($value)) {
                return false;
            }
        }

        return true;
    }

    /** "Task", "task 2" and "Task" all count as the same repeated checklist label. */
    private function checklistBaseLabel(string $label): string
    {
        return (string) preg_replace('/\s*\d+$/', '', mb_strtolower(trim($label)));
    }

    /**
     * @param  array<int, array{label: string, values: array<int, string>}>  $columns
     * @param  array<int, bool>  $consumed
     */
    private function findColumnPosition(array $columns, string $label, array $consumed): ?int
    {
        foreach ($columns as $position => $column) {
            if (! isset($consumed[$position]) && strcasecmp(trim($column['label']), $label) === 0) {
                return $position;
            }
        }

        return null;
    }
}
