<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\BulkBoardItemsRequest;
use App\Http\Requests\Board\BulkMoveBoardItemsRequest;
use App\Http\Requests\Board\ReorderBoardItemsRequest;
use App\Http\Requests\Board\StoreBoardItemRequest;
use App\Http\Requests\Board\UpdateBoardItemParentRequest;
use App\Http\Requests\Board\UpdateBoardItemRequest;
use App\Http\Requests\Board\UpdateBoardItemValuesRequest;
use App\Http\Resources\BoardItemDetailResource;
use App\Http\Resources\BoardItemResource;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardItemFilterService;
use App\Services\Board\BoardViewResolver;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BoardItemController extends Controller
{
    public function __construct(
        private readonly BoardItemFilterService $filter_service,
        private readonly BoardViewResolver $view_resolver,
        private readonly NotificationService $notification_service,
    ) {}

    /**
     * GET /api/boards/{item}/items
     *
     * Returns every top-level item in the tab (`view_id` if given, otherwise
     * the board's primary tab) with its values and its entire subitem
     * subtree eager-loaded via `childrenRecursive`, optionally narrowed by a
     * `search` term. An item's tab is derived through its group
     * (`board_groups.board_view_id`), since every item requires a group.
     * Grouping/sorting/hiding/coloring is derived client-side by
     * `useBoardToolbar` from this full set — since it only ever sees roots,
     * subitems are invisible to filter/sort/search/group-by by design; they
     * only ever render nested beneath their (visible, expanded) parent.
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $view = $this->view_resolver->resolveForRead($item, $this->viewIdParam($request));

        if (! $view) {
            return response()->json(['data' => []]);
        }

        $query = $item->items()
            ->where('is_archived', false)
            ->whereNull('parent_id')
            ->whereHas('group', fn ($q) => $q->where('board_view_id', $view->id))
            ->with(['values', 'childrenRecursive'])
            ->withCount([
                'comments',
                'commentAttachments',
                'attachments',
                'checklistItems as checklist_total_count',
                'checklistItems as checklist_done_count' => fn ($q) => $q->where('is_done', true),
                'children as subitem_count',
            ])
            ->orderBy('group_id')->orderBy('position');

        $query = $this->filter_service->applySearch($query, $request->query('search'));

        return response()->json([
            'data' => BoardItemResource::collection($query->get()),
        ]);
    }

    /**
     * GET /api/boards/{item}/items/{board_item}
     *
     * Resolves a single item for the `/boards/{board_id}/pulses/{id}` drawer.
     */
    public function show(WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);

        $board_item->load(['values', 'group', 'creator', 'checklistItems']);

        return response()->json(new BoardItemDetailResource($board_item));
    }

    /**
     * POST /api/boards/{item}/items
     *
     * `parent_id` is optional: when given, this creates a subitem of that
     * item instead of a top-level row — the subitem's `group_id` is always
     * inherited from its parent (any client-supplied `group_id` is ignored)
     * so a subitem's denormalized group never diverges from its parent's.
     */
    public function store(StoreBoardItemRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();
        $parent_id = $validated['parent_id'] ?? null;

        if ($parent_id !== null) {
            $parent = BoardItem::where('id', $parent_id)->firstOrFail();
            $group_id = $parent->group_id;
            $position = $validated['position'] ?? $this->nextPosition($item, $group_id, $parent_id);
        } else {
            $group_id = $validated['group_id'];
            $position = $validated['position'] ?? $this->nextPosition($item, $group_id);
        }

        $board_item = $item->items()->create([
            'group_id' => $group_id,
            'parent_id' => $parent_id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'position' => $position,
            'created_by_id' => $request->user()?->id,
        ]);

        if (! empty($validated['values'])) {
            $this->syncValues($item, $board_item, $validated['values'], $request->user());
        }

        return response()->json([
            'message' => 'Item created successfully.',
            'item' => new BoardItemResource($board_item->fresh('values')),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/items/{board_item}
     *
     * Renames the item and/or moves it to a different group. Since a
     * subitem's `group_id` is denormalized from its parent, moving an item
     * that has children cascades the new `group_id` onto every descendant
     * too — otherwise a descendant would keep pointing at a group its
     * parent no longer belongs to.
     */
    public function update(UpdateBoardItemRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);

        $validated = $request->validated();
        $board_item->fill($validated)->save();

        if (array_key_exists('group_id', $validated)) {
            $this->cascadeGroupToDescendants($board_item, $validated['group_id']);
        }

        return response()->json([
            'message' => 'Item updated successfully.',
            'item' => new BoardItemResource($board_item->fresh('values')),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/items/{board_item}/parent
     *
     * Row menu's "Convert to subitem" / "Convert to item" — the one boundary
     * `reorder()` deliberately can't cross. `parent_id` set converts a root
     * item into a subitem of that item, landing at the end of its subitem
     * list; `parent_id` null promotes a subitem back to a root item, landing
     * at the end of the given `group_id`. Cascades the resulting `group_id`
     * onto the moved item's own descendants, same as `update()`.
     */
    public function updateParent(UpdateBoardItemParentRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);

        $validated = $request->validated();
        $parent_id = $validated['parent_id'] ?? null;

        if ($parent_id !== null) {
            $parent = $item->items()->findOrFail($parent_id);
            $group_id = $parent->group_id;
            $position = $this->nextPosition($item, $group_id, $parent_id);
        } else {
            $group_id = $validated['group_id'];
            $position = $this->nextPosition($item, $group_id);
        }

        $board_item->update([
            'parent_id' => $parent_id,
            'group_id' => $group_id,
            'position' => $position,
        ]);

        $this->cascadeGroupToDescendants($board_item, $group_id);

        return response()->json([
            'message' => 'Item moved successfully.',
            'item' => new BoardItemResource($board_item->fresh('values')),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/items/{board_item}/values
     *
     * Inline cell edits — accepts a `{column_id: value}` map.
     */
    public function updateValues(UpdateBoardItemValuesRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);

        $this->syncValues($item, $board_item, $request->validated()['values'], $request->user());

        return response()->json([
            'message' => 'Item updated successfully.',
            'item' => new BoardItemResource($board_item->fresh('values')),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/items/{board_item}
     */
    public function destroy(WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);

        $this->deleteSubtree($board_item);

        return response()->json([
            'message' => 'Item deleted successfully.',
        ]);
    }

    /**
     * POST /api/boards/{item}/items/duplicate
     *
     * Selection action bar's "Duplicate" (and the row menu's own single-item
     * "Duplicate") — copies each given item's name, description and column
     * values into its own original group, appended after that group's
     * existing rows. Also deep-copies the item's entire subitem subtree by
     * default (each descendant keeps its own name/description/values),
     * matching Monday's own "duplicating an item duplicates its subitems"
     * behavior — pass `with_subitems: false` to copy just the item itself.
     */
    public function bulkDuplicate(BulkBoardItemsRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $originals = $item->items()->with(['values', 'childrenRecursive'])->whereIn('id', $request->validated()['item_ids'])->get();
        $with_subitems = $request->boolean('with_subitems', true);

        $duplicates = $originals->map(
            fn (BoardItem $original) => $this->copySubtree($item, $original, $original->group_id, null, $this->nextPosition($item, $original->group_id), $with_subitems)
        );

        return response()->json([
            'message' => 'Items duplicated successfully.',
            'items' => BoardItemResource::collection($duplicates),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/items/move
     *
     * Selection action bar's "Move to" — moves every given item into a
     * different group (table), appended at the end of the target group.
     * Cascades the new `group_id` onto every descendant, same as `update()`.
     */
    public function bulkMove(BulkMoveBoardItemsRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();
        $group_id = (int) $validated['group_id'];

        $items = $item->items()->whereIn('id', $validated['item_ids'])->get();

        foreach ($items as $board_item) {
            $board_item->update([
                'group_id' => $group_id,
                'position' => $this->nextPosition($item, $group_id),
            ]);
            $this->cascadeGroupToDescendants($board_item, $group_id);
        }

        return response()->json([
            'message' => 'Items moved successfully.',
            'items' => BoardItemResource::collection($items->fresh('values')),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/items/reorder
     *
     * Drag-and-drop reordering. `scope=root` resequences a table's root
     * items (`target_ordered_ids`) and, when the dragged item was dropped
     * into a *different* table, also moves it there (cascading the new
     * `group_id` onto its descendants, same as `update()`/`bulkMove()`) and
     * resequences the vacated table's remaining items (`source_ordered_ids`).
     * `scope=subitem` resequences one item's subitems (`target_ordered_ids`)
     * — subitems never change parent through this endpoint. Every id in
     * both ordered-id lists is validated (by {@see ReorderBoardItemsRequest})
     * to already belong to the list it claims, so this never promotes a
     * subitem to root or vice versa. All position writes happen in one
     * transaction so a partial reorder can never persist.
     */
    public function reorder(ReorderBoardItemsRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();
        $touched_ids = [];

        DB::transaction(function () use ($item, $validated, &$touched_ids) {
            if ($validated['scope'] === 'root') {
                $moved_item = $item->items()->findOrFail($validated['moved_item_id']);
                $this->ensureItemBelongsToBoard($item, $moved_item);

                if ($moved_item->group_id !== (int) $validated['target_group_id']) {
                    $moved_item->update(['group_id' => (int) $validated['target_group_id']]);
                    $this->cascadeGroupToDescendants($moved_item, (int) $validated['target_group_id']);
                }

                foreach ($validated['target_ordered_ids'] as $position => $id) {
                    BoardItem::where('id', $id)->where('board_id', $item->id)->update(['position' => $position]);
                }
                $touched_ids = $validated['target_ordered_ids'];

                if (! empty($validated['source_ordered_ids'])) {
                    foreach ($validated['source_ordered_ids'] as $position => $id) {
                        BoardItem::where('id', $id)->where('board_id', $item->id)->update(['position' => $position]);
                    }
                    $touched_ids = [...$touched_ids, ...$validated['source_ordered_ids']];
                }
            } else {
                foreach ($validated['target_ordered_ids'] as $position => $id) {
                    BoardItem::where('id', $id)
                        ->where('board_id', $item->id)
                        ->where('parent_id', $validated['target_parent_id'])
                        ->update(['position' => $position]);
                }
                $touched_ids = $validated['target_ordered_ids'];
            }
        });

        $items = BoardItem::whereIn('id', $touched_ids)->with('values')->get();

        return response()->json([
            'message' => 'Items reordered successfully.',
            'items' => BoardItemResource::collection($items),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/items/archive
     *
     * Selection action bar's "Archive" — hides every given item from the
     * board without deleting it, unlike `bulkDestroy()` this leaves
     * `deleted_at` unset.
     */
    public function bulkArchive(BulkBoardItemsRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $item->items()->whereIn('id', $request->validated()['item_ids'])->update(['is_archived' => true]);

        return response()->json([
            'message' => 'Items archived successfully.',
        ]);
    }

    /**
     * DELETE /api/boards/{item}/items
     *
     * Selection action bar's "Delete" — bulk counterpart of `destroy()`,
     * soft-deletes every given item and its descendants. Loads each item and
     * deletes it through `deleteSubtree()` rather than a single mass
     * `whereIn(...)->delete()` query, since a mass query-builder delete
     * bypasses Eloquent entirely and would leave any subitems orphaned.
     */
    public function bulkDestroy(BulkBoardItemsRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $items = $item->items()->whereIn('id', $request->validated()['item_ids'])->get();

        foreach ($items as $board_item) {
            $this->deleteSubtree($board_item);
        }

        return response()->json([
            'message' => 'Items deleted successfully.',
        ]);
    }

    /**
     * Upserts one {@link BoardItemValue} per entry in `$values`, silently
     * skipping any column id that doesn't belong to this item's own tab
     * (columns are per-tab, so a column from a different tab of the same
     * board is rejected too, not just columns from other boards).
     *
     * When `$actor` is given, newly-added people on a people-type column
     * trigger an "Assigned you" notification, see {@see notifyNewlyAssignedPeople()}.
     *
     * @param  array<string, mixed>  $values
     */
    private function syncValues(WorkspaceNavigationItem $item, BoardItem $board_item, array $values, ?User $actor = null): void
    {
        // A subitem may only be assigned values for subitem-scoped columns,
        // and a root item only for item-scoped ones — the two column sets are
        // independent, mirroring how monday.com's subitems carry their own
        // separate columns rather than reusing the parent item's.
        $scope = $board_item->parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;

        $valid_columns = BoardColumn::where('board_view_id', $board_item->group->board_view_id)
            ->where('scope', $scope)
            ->whereIn('id', array_map('intval', array_keys($values)))
            ->get(['id', 'type'])
            ->keyBy('id');

        foreach ($values as $column_id => $value) {
            $column = $valid_columns->get((int) $column_id);
            if (! $column) {
                continue;
            }

            if ($actor && $column->type === BoardColumn::TYPE_PEOPLE) {
                $this->notifyNewlyAssignedPeople($item, $board_item, $column, $value, $actor);
            }

            $board_item->values()->updateOrCreate(
                ['column_id' => $column->id],
                ['value' => $value]
            );
        }
    }

    /**
     * Notifies every person newly added to a people-type column value
     * (comparing against the currently-stored value), skipping self-assignment.
     */
    private function notifyNewlyAssignedPeople(WorkspaceNavigationItem $item, BoardItem $board_item, BoardColumn $column, mixed $new_value, User $actor): void
    {
        $existing_value = BoardItemValue::where('item_id', $board_item->id)->where('column_id', $column->id)->first();
        $existing_ids = is_array($existing_value?->value) ? $existing_value->value : [];
        $new_ids = is_array($new_value) ? $new_value : [];

        foreach (array_diff($new_ids, $existing_ids) as $newly_added_id) {
            if ($person = User::find($newly_added_id)) {
                $this->notification_service->notify(
                    recipient: $person,
                    actor: $actor,
                    type: Notification::TYPE_ASSIGNED,
                    board: $item,
                    action_label: 'Assigned you',
                    action_target: sprintf('to "%s" on the Board "%s"', $board_item->name, $item->label),
                    link: "/boards/{$item->id}/pulses/{$board_item->id}",
                );
            }
        }
    }

    /**
     * Guard: abort with 404 when the item is not part of the board.
     */
    private function ensureItemBelongsToBoard(WorkspaceNavigationItem $item, BoardItem $board_item): void
    {
        abort_if($board_item->board_id !== $item->id, 404);
    }

    /**
     * The next free position among a group's items (append to the end).
     * Root items (`$parent_id === null`) and a given item's subitems each
     * have their own independent position sequence.
     */
    private function nextPosition(WorkspaceNavigationItem $item, int $group_id, ?int $parent_id = null): int
    {
        return (int) $item->items()->where('group_id', $group_id)->where('parent_id', $parent_id)->max('position') + 1;
    }

    /**
     * Soft-delete an item and every descendant (soft-deletes don't trigger
     * the DB's `cascadeOnDelete`, so this has to walk the subtree itself).
     */
    private function deleteSubtree(BoardItem $board_item): void
    {
        $board_item->loadMissing('childrenRecursive');

        foreach ($this->flattenTree($board_item->childrenRecursive) as $descendant) {
            $descendant->delete();
        }

        $board_item->delete();
    }

    /**
     * Recursively deep-copy an item and its subtree. `$parent_id` is the new
     * parent for the copy (`null` for a root); the "(copy)" suffix is only
     * appended when the copy stays a sibling of the original (i.e. this is
     * the top of the duplicate operation, not a recursive descendant copy).
     */
    private function copySubtree(WorkspaceNavigationItem $item, BoardItem $original, int $group_id, ?int $parent_id, int $position, bool $with_children = true): BoardItem
    {
        $copy = $item->items()->create([
            'group_id' => $group_id,
            'parent_id' => $parent_id,
            'name' => $parent_id === $original->parent_id ? "{$original->name} (copy)" : $original->name,
            'description' => $original->description,
            'position' => $position,
            'created_by_id' => $original->created_by_id,
        ]);

        foreach ($original->values as $value) {
            $copy->values()->create([
                'column_id' => $value->column_id,
                'value' => $value->value,
            ]);
        }

        if ($with_children) {
            foreach ($original->childrenRecursive as $child) {
                $this->copySubtree($item, $child, $group_id, $copy->id, $child->position);
            }
        }

        // Reload `childrenRecursive` (not just `values`) so the copy's own
        // freshly-created subtree is actually present in the API response —
        // `BoardItemResource`'s `children` key resolves to an empty list
        // whenever this relation isn't loaded, mirroring
        // `WorkspaceNavigationItemController::duplicate()`'s same reload.
        return $copy->fresh(['values', 'childrenRecursive']);
    }

    /**
     * Propagates a new `group_id` onto every descendant of `$board_item`,
     * keeping each subitem's denormalized `group_id` in sync with its
     * ancestor chain after the ancestor moves to a different group.
     */
    private function cascadeGroupToDescendants(BoardItem $board_item, int $group_id): void
    {
        $board_item->loadMissing('childrenRecursive');

        foreach ($this->flattenTree($board_item->childrenRecursive) as $descendant) {
            $descendant->update(['group_id' => $group_id]);
        }
    }

    /**
     * Flatten a nested collection of items into a single list.
     *
     * @param  iterable<BoardItem>  $items
     * @return array<int, BoardItem>
     */
    private function flattenTree(iterable $items): array
    {
        $flat = [];

        foreach ($items as $item) {
            $flat[] = $item;
            $flat = array_merge($flat, $this->flattenTree($item->childrenRecursive));
        }

        return $flat;
    }

    /**
     * Reads `view_id` from the query string for GET requests.
     */
    private function viewIdParam(Request $request): ?int
    {
        return $request->filled('view_id') ? (int) $request->query('view_id') : null;
    }
}
