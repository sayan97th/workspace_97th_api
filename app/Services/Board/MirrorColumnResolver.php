<?php

namespace App\Services\Board;

use App\Http\Controllers\Workspace\WorkspaceController;
use App\Http\Resources\BoardItemResource;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use Illuminate\Support\Collection;

/**
 * Computes every `BoardColumn::TYPE_MIRROR` column's value for a batch of
 * items and attaches it as a non-persisted `mirror_values` attribute on each
 * one (`{column_id: value}`), the same "attach a computed attribute before
 * serializing" convention {@see WorkspaceController::index()}
 * already uses for `membership_role`. {@see BoardItemResource}
 * merges it into the flattened `values` map, so a mirror cell needs zero
 * special-casing on the frontend, it just shows up like any other value.
 *
 * A mirror column has no {@see BoardItemValue} of its own: it always reads,
 * for each item, its `config.source_column_id` connect-board column's linked
 * item ids, then those linked items' own value for `config.mirrored_column_id`.
 * Every lookup is batched across the whole item set (one query per mirror
 * column, plus one for the connect-board values), never per item, so a
 * board's `index()` listing stays a handful of queries regardless of how many
 * rows or mirror columns it has.
 */
class MirrorColumnResolver
{
    /**
     * @param  Collection<int, BoardItem>  $items
     * @param  Collection<int, BoardColumn>  $columns  Every column in the item set's tab+scope (not just mirror/connect-board ones).
     */
    public function attach(Collection $items, Collection $columns): void
    {
        $mirror_columns = $columns->where('type', BoardColumn::TYPE_MIRROR);
        if ($mirror_columns->isEmpty() || $items->isEmpty()) {
            return;
        }

        $connect_column_ids = $columns->where('type', BoardColumn::TYPE_CONNECT_BOARD)->pluck('id');
        $item_ids = $items->pluck('id');

        $connect_values = BoardItemValue::whereIn('item_id', $item_ids)
            ->whereIn('column_id', $connect_column_ids)
            ->get()
            ->keyBy(fn (BoardItemValue $value) => "{$value->item_id}:{$value->column_id}");

        foreach ($mirror_columns as $mirror_column) {
            $source_column_id = $mirror_column->config['source_column_id'] ?? null;
            $mirrored_column_id = $mirror_column->config['mirrored_column_id'] ?? null;
            if (! $source_column_id || ! $mirrored_column_id) {
                continue;
            }

            $linkedIdsFor = function (BoardItem $item) use ($connect_values, $source_column_id): array {
                $connect_value = $connect_values->get("{$item->id}:{$source_column_id}");

                return $connect_value !== null ? (array) $connect_value->value : [];
            };

            $all_linked_ids = $items->flatMap($linkedIdsFor)->unique()->values();
            if ($all_linked_ids->isEmpty()) {
                continue;
            }

            $mirrored_values = BoardItemValue::whereIn('item_id', $all_linked_ids)
                ->where('column_id', $mirrored_column_id)
                ->get()
                ->keyBy('item_id');

            foreach ($items as $item) {
                $resolved = collect($linkedIdsFor($item))
                    ->map(fn ($linked_item_id) => $mirrored_values->get($linked_item_id)?->value)
                    ->filter(fn ($value) => $value !== null)
                    ->values();

                if ($resolved->isEmpty()) {
                    continue;
                }

                $mirror_values = $item->getAttribute('mirror_values') ?? [];
                // A single linked item mirrors as that one value directly (matching every
                // other column's own scalar/array shape); more than one mirrors as a list.
                $mirror_values[(string) $mirror_column->id] = $resolved->count() === 1 ? $resolved->first() : $resolved->all();
                $item->setAttribute('mirror_values', $mirror_values);
            }
        }
    }
}
