<?php

namespace App\Services\Board\Export;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardTag;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The names a board export needs that live outside the item's own row:
 * people, tags, linked or predecessor items and the columns a Mirror reads.
 * {@see prime()} loads everything one chunk of items refers to in a handful
 * of `whereIn` queries, never one query per cell, so a 50,000 row export
 * costs the same few queries per 500 rows as the first chunk did.
 *
 * People, tags and mirrored columns are few and repeat across chunks, so
 * they stay cached for the whole export. Item names do not repeat much and
 * could grow without bound, so they are reset on every chunk.
 */
class BoardExportLookups
{
    /** @var array<int|string, string> user id => full name */
    private array $people = [];

    /** @var array<int|string, array{label: string, color: string|null}> tag id => tag */
    private array $tags = [];

    /** @var array<int|string, string> item id => name, for the current chunk only */
    private array $item_names = [];

    /** @var array<int, BoardColumn|null> column id => the column a Mirror reads */
    private array $mirrored_columns = [];

    /**
     * Loads every name `$items` (and their loaded subitems) refer to through `$columns`.
     *
     * @param  Collection<int, BoardItem>  $items
     * @param  Collection<int, BoardColumn>  $columns
     */
    public function prime(Collection $items, Collection $columns): void
    {
        $this->item_names = [];

        $people_column_ids = $columns->whereIn('type', [BoardColumn::TYPE_PEOPLE, BoardColumn::TYPE_VOTE])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $tags_column_ids = $columns->where('type', BoardColumn::TYPE_TAGS)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $linked_column_ids = $columns->whereIn('type', [BoardColumn::TYPE_CONNECT_BOARD, BoardColumn::TYPE_DEPENDENCY])->pluck('id')->map(fn ($id) => (int) $id)->all();

        $person_ids = [];
        $tag_ids = [];
        $linked_item_ids = [];

        foreach ($items as $item) {
            $person_ids[] = $item->created_by_id;
            if ($item->relationLoaded('latestActivity')) {
                $person_ids[] = $item->latestActivity?->user_id;
            }

            foreach ($item->values as $value) {
                $ids = is_array($value->value) ? $value->value : [];
                if (in_array((int) $value->column_id, $people_column_ids, true)) {
                    array_push($person_ids, ...$ids);
                } elseif (in_array((int) $value->column_id, $tags_column_ids, true)) {
                    array_push($tag_ids, ...$ids);
                } elseif (in_array((int) $value->column_id, $linked_column_ids, true)) {
                    array_push($linked_item_ids, ...$ids);
                }
            }
        }

        $this->loadPeople($person_ids);
        $this->loadTags($tag_ids);
        $this->loadItemNames($linked_item_ids);
        $this->loadMirroredColumns($columns);
    }

    public function personName(mixed $user_id): ?string
    {
        return is_scalar($user_id) ? ($this->people[(string) $user_id] ?? null) : null;
    }

    /**
     * @return array{label: string, color: string|null}|null
     */
    public function tag(mixed $tag_id): ?array
    {
        return is_scalar($tag_id) ? ($this->tags[(string) $tag_id] ?? null) : null;
    }

    public function itemName(mixed $item_id): ?string
    {
        return is_scalar($item_id) ? ($this->item_names[(string) $item_id] ?? null) : null;
    }

    public function mirroredColumn(BoardColumn $mirror_column): ?BoardColumn
    {
        $column_id = (int) ($mirror_column->config['mirrored_column_id'] ?? 0);

        return $column_id > 0 ? ($this->mirrored_columns[$column_id] ?? null) : null;
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private function loadPeople(array $ids): void
    {
        $missing = $this->missingIds($ids, $this->people);
        if ($missing === []) {
            return;
        }

        User::withTrashed()->whereIn('id', $missing)->get()->each(function (User $user) {
            $this->people[(string) $user->id] = (string) $user->full_name;
        });

        foreach ($missing as $id) {
            $this->people[(string) $id] ??= '';
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private function loadTags(array $ids): void
    {
        $missing = $this->missingIds($ids, $this->tags);
        if ($missing === []) {
            return;
        }

        BoardTag::whereIn('id', $missing)->get(['id', 'label', 'color'])->each(function (BoardTag $tag) {
            $this->tags[(string) $tag->id] = ['label' => (string) $tag->label, 'color' => $tag->color];
        });
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private function loadItemNames(array $ids): void
    {
        $missing = $this->missingIds($ids, $this->item_names);
        if ($missing === []) {
            return;
        }

        foreach (array_chunk($missing, 1000) as $batch) {
            BoardItem::withTrashed()->whereIn('id', $batch)->get(['id', 'name'])->each(function (BoardItem $item) {
                $this->item_names[(string) $item->id] = (string) $item->name;
            });
        }
    }

    /**
     * @param  Collection<int, BoardColumn>  $columns
     */
    private function loadMirroredColumns(Collection $columns): void
    {
        $missing = $columns->where('type', BoardColumn::TYPE_MIRROR)
            ->map(fn (BoardColumn $column) => (int) ($column->config['mirrored_column_id'] ?? 0))
            ->filter(fn (int $id) => $id > 0 && ! array_key_exists($id, $this->mirrored_columns))
            ->unique()
            ->values();

        if ($missing->isEmpty()) {
            return;
        }

        $found = BoardColumn::whereIn('id', $missing)->get()->keyBy('id');
        foreach ($missing as $id) {
            $this->mirrored_columns[$id] = $found->get($id);
        }
    }

    /**
     * The numeric ids of `$ids` not yet in `$cache`.
     *
     * @param  array<int, mixed>  $ids
     * @param  array<int|string, mixed>  $cache
     * @return array<int, int>
     */
    private function missingIds(array $ids, array $cache): array
    {
        $missing = [];
        foreach ($ids as $id) {
            if (is_numeric($id) && ! array_key_exists((string) $id, $cache)) {
                $missing[(int) $id] = (int) $id;
            }
        }

        return array_values($missing);
    }
}
