<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\BoardTag;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns an item's stored cell values into the plain text the picker searches, resolving
 * option ids, people and tags to their names with one query each for the whole board.
 * Used by {@see ConnectBoardColumnService}.
 */
final class ConnectItemSearchText
{
    /** @var Collection<string, string> */
    private Collection $people_names;

    /** @var Collection<string, string> */
    private Collection $tag_labels;

    /**
     * @param  Collection<int, BoardColumn>  $columns  Keyed by id.
     * @param  Collection<int, BoardItem>  $items
     */
    public function __construct(private readonly Collection $columns, Collection $items)
    {
        $people_column_ids = $columns->where('type', BoardColumn::TYPE_PEOPLE)->keys()->all();
        $user_ids = $items->flatMap(fn (BoardItem $item) => $item->values->whereIn('column_id', $people_column_ids)->flatMap(fn (BoardItemValue $value) => (array) $value->value))
            ->filter(fn ($id) => is_numeric($id))
            ->unique()
            ->values();

        $this->people_names = $user_ids->isEmpty()
            ? collect()
            : User::whereIn('id', $user_ids)->get()->mapWithKeys(fn (User $user) => [(string) $user->id => (string) $user->full_name]);

        // A cell can hold the board's own tags or public tags of other boards (see `TagCatalog`), so tags load by id.
        $tags_column_ids = $columns->where('type', BoardColumn::TYPE_TAGS)->keys()->all();
        $tag_ids = $items->flatMap(fn (BoardItem $item) => $item->values->whereIn('column_id', $tags_column_ids)->flatMap(fn (BoardItemValue $value) => (array) $value->value))
            ->filter(fn ($id) => is_numeric($id))
            ->unique()
            ->values();

        $this->tag_labels = $tag_ids->isEmpty()
            ? collect()
            : BoardTag::whereIn('id', $tag_ids)->get(['id', 'label'])->mapWithKeys(fn (BoardTag $tag) => [(string) $tag->id => (string) $tag->label]);
    }

    /**
     * @return array<string, string>
     */
    public function forItem(BoardItem $item, int $max_length): array
    {
        $texts = [];
        foreach ($item->values as $value) {
            $column = $this->columns->get($value->column_id);
            $text = $column ? trim($this->text($column, $value->value)) : '';
            if ($text !== '') {
                $texts[(string) $value->column_id] = Str::limit($text, $max_length, '');
            }
        }

        return $texts;
    }

    private function text(BoardColumn $column, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        return match ($column->type) {
            BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DROPDOWN => $this->optionLabels($column, (array) $value),
            BoardColumn::TYPE_PEOPLE => collect((array) $value)->map(fn ($id) => $this->people_names->get((string) $id))->filter()->implode(', '),
            BoardColumn::TYPE_TAGS => collect((array) $value)->map(fn ($id) => $this->tag_labels->get((string) $id, is_scalar($id) ? (string) $id : ''))->filter()->implode(', '),
            BoardColumn::TYPE_CHECKBOX => in_array($value, [true, 1, '1', 'true'], true) ? 'Checked' : '',
            BoardColumn::TYPE_TIMELINE => is_array($value) ? trim(($value['start'] ?? '').' '.($value['end'] ?? '')) : '',
            BoardColumn::TYPE_LINK => is_array($value) ? trim(($value['text'] ?? '').' '.($value['url'] ?? '')) : (string) $value,
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private function optionLabels(BoardColumn $column, array $ids): string
    {
        $labels = collect($column->config['options'] ?? [])->mapWithKeys(fn ($option) => [(string) ($option['id'] ?? '') => (string) ($option['label'] ?? '')]);

        return collect($ids)->map(fn ($id) => $labels->get((string) $id, ''))->filter()->implode(', ');
    }
}
