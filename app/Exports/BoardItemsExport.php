<?php

namespace App\Exports;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Board options menu's "Export board to Excel" — one worksheet with a row
 * per top-level item (subitems aren't flattened in; they have their own,
 * separate column set) and a column per item-scoped board column, values
 * rendered the same human-readable way the Table view's cells read
 * (option ids resolved to their label, people ids to full names, ...).
 */
class BoardItemsExport implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  Collection<int, BoardItem>  $items
     * @param  Collection<int, BoardColumn>  $columns  item-scoped columns, in display order
     */
    public function __construct(
        private readonly string $board_label,
        private readonly Collection $items,
        private readonly Collection $columns,
        private readonly Collection $people_by_id,
    ) {}

    public function title(): string
    {
        return mb_substr($this->board_label, 0, 31) ?: 'Board';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Item', ...$this->columns->pluck('label')->all()];
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        return $this->items->map(function (BoardItem $item) {
            $values_by_column_id = $item->values->keyBy('column_id');

            return [
                $item->name,
                ...$this->columns->map(fn (BoardColumn $column) => $this->formatValue(
                    $column,
                    $values_by_column_id->get($column->id)?->value
                ))->all(),
            ];
        })->all();
    }

    private function formatValue(BoardColumn $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($column->type) {
            BoardColumn::TYPE_PEOPLE => collect(is_array($value) ? $value : [])
                ->map(fn ($id) => $this->people_by_id->get((int) $id)?->full_name ?? "#{$id}")
                ->implode(', '),
            BoardColumn::TYPE_STATUS, BoardColumn::TYPE_DROPDOWN, BoardColumn::TYPE_LABEL => $this->optionLabel($column, $value),
            BoardColumn::TYPE_TAGS => collect(is_array($value) ? $value : [])
                ->map(fn ($option_id) => $this->optionLabel($column, $option_id))
                ->implode(', '),
            BoardColumn::TYPE_CHECKBOX => $value ? 'Yes' : 'No',
            default => $this->stringify($value),
        };
    }

    /**
     * Flattens a stored cell value into readable text. Structured values
     * (a timeline range, a link, a list of files) used to be imploded
     * as is, which threw "Array to string conversion" on nested arrays.
     */
    private function stringify(mixed $value): string
    {
        if (! is_array($value)) {
            return is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
        }

        if (array_is_list($value)) {
            return collect($value)->map(fn ($entry) => $this->stringify($entry))->filter(fn ($text) => $text !== '')->implode(', ');
        }

        if (isset($value['from']) || isset($value['to'])) {
            return trim(($value['from'] ?? '').' - '.($value['to'] ?? ''), ' -');
        }

        foreach (['text', 'label', 'name', 'title', 'url', 'value', 'date'] as $key) {
            if (isset($value[$key]) && ! is_array($value[$key]) && $value[$key] !== '') {
                return (string) $value[$key];
            }
        }

        return (string) json_encode($value);
    }

    private function optionLabel(BoardColumn $column, mixed $option_id): string
    {
        $option = collect($column->config['options'] ?? [])->firstWhere('id', $option_id);

        return $option['label'] ?? (string) $option_id;
    }
}
