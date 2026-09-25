<?php

namespace App\Http\Resources;

use App\Models\BoardItem;
use App\Services\Board\ColumnPermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A board item ("pulse") row, with its column values flattened into a
 * `{column_id: value}` map so the frontend can look a cell's value up by
 * the column id directly.
 *
 * @mixin BoardItem
 */
class BoardItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'group_id' => $this->group_id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'description' => $this->description,
            'position' => $this->position,
            'is_archived' => $this->is_archived,
            'is_priority' => $this->is_priority,
            // Direct subitem count, for the collapsed-row "N Subitems" badge —
            // falls back to a loaded `children` count when the `withCount`
            // alias isn't present (mirrors `checklist_total_count` below).
            'subitem_count' => $this->subitem_count
                ?? ($this->relationLoaded('children') ? $this->children->count() : 0),
            // The item's full subitem subtree, nested recursively — only
            // `index()` (and a duplicated item's own response) eager-load
            // `childrenRecursive`; every other action (store/update/
            // updateValues) resolves this to a real empty array. Deliberately
            // not `self::collection($this->whenLoaded(...))`: with no relation
            // loaded, that resolves to a `MissingValue`-backed collection that
            // the framework strips from the JSON entirely rather than emitting
            // `[]`, which would leave `children` undefined client-side even
            // though the frontend type declares it as a required array.
            'children' => $this->relationLoaded('childrenRecursive')
                ? self::collection($this->childrenRecursive)
                : [],
            // The Row menu's "Set recurring..." schedule, when this item has
            // one — only `index()`/`show()` eager-load `recurrence`; every
            // other action (store/update/updateValues) resolves this to null
            // rather than a real lookup, same convention as `children` above.
            'recurrence' => $this->relationLoaded('recurrence') && $this->recurrence
                ? ['frequency' => $this->recurrence->frequency, 'interval_count' => $this->recurrence->interval_count]
                : null,
            // Only `index()` eager-loads the `comments`/`commentAttachments` counts
            // (the board table's row chat icon and the Kanban card's attachment
            // count); other actions that return this resource (store/update/
            // updateValues) fall back to 0 rather than a real count.
            'comment_count' => $this->whenCounted('comments', default: 0),
            // Sums attachments uploaded directly to the item (the Kanban
            // drawer's "Attachments" affordance) and files sent along with a
            // comment — both count toward the card's single attachment badge.
            'attachment_count' => $this->whenCounted('attachments', default: 0) + $this->whenCounted('commentAttachments', default: 0),
            // `checklistItems` is counted twice under different aliases (total,
            // and just the done ones) to build the Kanban card's "✓ done/total"
            // badge, which doesn't fit `whenCounted()`'s single-relation-count
            // helper — read the aliased attributes directly instead, falling
            // back to a real count when the relation itself was eager-loaded
            // (e.g. the item detail drawer's `show()`), or 0 otherwise.
            'checklist_total_count' => $this->checklist_total_count
                ?? ($this->relationLoaded('checklistItems') ? $this->checklistItems->count() : 0),
            'checklist_done_count' => $this->checklist_done_count
                ?? ($this->relationLoaded('checklistItems') ? $this->checklistItems->where('is_done', true)->count() : 0),
            // Cast to a plain object: if every column id in this map happens to be an
            // integer key, Laravel's JsonResource::removeMissingValues() treats the
            // array as a list and silently reindexes it from 0 via array_values(),
            // destroying the column-id keys. An stdClass isn't array-shaped, so it
            // skips that pass and round-trips through json_encode() untouched.
            //
            // Built with `+` (array union), not `[...$a, ...$b]` — a column id key
            // survives `mapWithKeys()` as a PHP *integer* (a numeric string key is
            // always coerced back to int), and the `...` spread operator, unlike
            // `+`, only preserves string keys: an integer-keyed array unpacked with
            // `...` is silently renumbered from 0, which reproduces the exact bug
            // this cast is here to prevent, just one line earlier.
            //
            // `mirror_values` (set by `MirrorColumnResolver::attach()`, see
            // `BoardItemController`) is merged in the same shape so a Mirror
            // column's computed value shows up in `values` exactly like any
            // other column's, with no frontend special-casing needed.
            //
            // Values of a column the viewer may not see (column permissions,
            // see `ColumnPermissionService`) are dropped here, so every
            // endpoint returning this resource hides them the same way.
            'values' => $this->whenLoaded(
                'values',
                fn () => (object) $this->visibleValues($request, ($this->mirror_values ?? []) + $this->values->mapWithKeys(fn ($value) => [(string) $value->column_id => $value->value])->all()),
                (object) $this->visibleValues($request, $this->mirror_values ?? [])
            ),
        ];
    }

    /**
     * Drops the values of every column the requesting user may not see.
     *
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    private function visibleValues(Request $request, array $values): array
    {
        $hidden_ids = app(ColumnPermissionService::class)->hiddenColumnIds((int) $this->board_id, $request->user());

        foreach ($hidden_ids as $column_id) {
            unset($values[$column_id], $values[(string) $column_id]);
        }

        return $values;
    }
}
