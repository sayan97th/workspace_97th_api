<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\BulkBoardItemsRequest;
use App\Http\Requests\Board\BulkMoveBoardItemsRequest;
use App\Http\Requests\Board\BulkSetColumnValueRequest;
use App\Http\Requests\Board\BulkUpdateBoardItemParentRequest;
use App\Http\Requests\Board\ReorderBoardItemsRequest;
use App\Http\Requests\Board\SetBoardItemRecurrenceRequest;
use App\Http\Requests\Board\StoreBoardItemRequest;
use App\Http\Requests\Board\UpdateBoardItemParentRequest;
use App\Http\Requests\Board\UpdateBoardItemRequest;
use App\Http\Requests\Board\UpdateBoardItemValuesRequest;
use App\Http\Resources\BoardItemDetailResource;
use App\Http\Resources\BoardItemResource;
use App\Models\BoardActivityLog;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemRecurrence;
use App\Models\BoardItemValue;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardActivityLogger;
use App\Services\Board\BoardAutomationService;
use App\Services\Board\BoardDependencyScheduler;
use App\Services\Board\BoardItemActivityService;
use App\Services\Board\BoardItemCommentCounter;
use App\Services\Board\BoardItemFilterService;
use App\Services\Board\BoardItemScopeConversionService;
use App\Services\Board\BoardItemValueService;
use App\Services\Board\BoardViewResolver;
use App\Services\Board\ColumnPermissionService;
use App\Services\Board\MirrorColumnResolver;
use App\Services\Board\RecurringItemService;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BoardItemController extends Controller
{
    public function __construct(
        private readonly BoardItemFilterService $filter_service,
        private readonly BoardViewResolver $view_resolver,
        private readonly MirrorColumnResolver $mirror_resolver,
        private readonly BoardAutomationService $automation_service,
        private readonly BoardItemValueService $value_service,
        private readonly BoardDependencyScheduler $dependency_scheduler,
        private readonly BoardItemScopeConversionService $scope_conversion_service,
        private readonly ColumnPermissionService $column_permissions,
        private readonly BoardItemActivityService $activity_service,
        private readonly BoardActivityLogger $activity_logger,
    ) {}

    /**
     * GET /api/boards/{item}/items
     *
     * Returns every top-level item in the tab (`view_id` if given, otherwise
     * the board's primary tab) with its values and its entire subitem
     * subtree eager-loaded via `childrenRecursive`, optionally narrowed by a
     * `search` term. Items of an archived group stay hidden with it. An item's tab is derived through its group
     * (`board_groups.board_view_id`), since every item requires a group.
     * Grouping/sorting/hiding/coloring is derived client-side by
     * `useBoardToolbar` from this full set — since it only ever sees roots,
     * subitems are invisible to filter/sort/search/group-by by design; they
     * only ever render nested beneath their (visible, expanded) parent.
     *
     * Optionally narrowed to specific tables via `group_ids[]` — the Table
     * view's `GroupSection` lazy-loads each table's rows only once it's
     * about to scroll into view (see `BoardTableView.tsx`), rather than
     * pulling a whole tab's items (which can span 100+ tables) up front.
     * Every other caller (Kanban/Calendar/Gantt's eager full-tab loads, a
     * `search`-only call) omits it and keeps getting the full tab, unchanged.
     *
     * Optionally narrowed by the toolbar's filters via `filter_state` (JSON),
     * `today` (`YYYY-MM-DD`) and `timezone` (IANA), see {@see BoardItemFilterService::applyFilterState()}.
     *
     * Optionally narrowed to specific root items via `item_ids[]` (at most 500), how a board
     * reloads the rows an automation changed without reloading whole tables.
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $view = $this->view_resolver->resolveForRead($item, $this->viewIdParam($request));

        if (! $view) {
            return response()->json(['data' => []]);
        }

        $viewer_id = $request->user()?->id;

        $query = $item->items()
            ->where('is_archived', false)
            ->whereNull('parent_id')
            ->whereHas('group', fn ($q) => $q->where('board_view_id', $view->id)->where('is_archived', false))
            ->with([
                'values',
                'dependencyLinks',
                'recurrence',
                'latestActivity',
                // An archived subitem is hidden like an archived root item, it
                // only comes back through the board's archive panel. Subitems
                // get the same per viewer mention flag as their parent row.
                'childrenRecursive' => fn ($q) => $q->where('is_archived', false)->withViewerMention($viewer_id),
            ])
            // Blue chat bubble on rows where the viewer is tagged, see BoardItem::scopeWithViewerMention().
            ->withViewerMention($viewer_id)
            ->withCount([
                // Scheduled updates are invisible until they go live, so they do not count yet,
                // same as BoardItemCommentCounter which keeps this number live.
                'comments' => fn ($q) => $q->visibleNow(),
                'commentAttachments',
                'attachments',
                'checklistItems as checklist_total_count',
                'checklistItems as checklist_done_count' => fn ($q) => $q->where('is_done', true),
                'children as subitem_count' => fn ($q) => $q->where('is_archived', false),
            ])
            ->orderBy('group_id')->orderBy('position');

        $query = $this->filter_service->applySearch($query, $request->query('search'));

        if ($group_ids = $this->groupIdsParam($request)) {
            $query->whereIn('group_id', $group_ids);
        }

        // Only these rows, the ones an automation just changed (see AutomationEffectCollector).
        // A requested row that does not come back was archived, deleted or moved out of the tab.
        if ($item_ids = $this->itemIdsParam($request)) {
            $query->whereIn('id', $item_ids);
        }

        $query = $this->filter_service->applyFilterState(
            $query,
            $this->filterStateParam($request),
            $view,
            $request->user()?->id,
            $request->query('today'),
            $request->query('timezone'),
        );

        $items = $query->get();
        $this->attachMirrorValues($items, $view->id, BoardColumn::SCOPE_ITEM);

        return response()->json([
            'data' => BoardItemResource::collection($items),
        ]);
    }

    /**
     * GET /api/boards/{item}/items/update-matches?q=&view_id=
     *
     * The toolbar Search box's "Updates and replies" option: ids of the tab's
     * root items with a published update or reply (on the item or one of its
     * subitems) containing `q`. Only ids come back, the rows themselves are
     * already loaded or fetched through `index()`.
     */
    public function updateMatches(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'view_id' => ['nullable', 'integer'],
        ]);
        abort_unless($request->user()->workspaces()->where('workspaces.id', $item->workspace_id)->exists(), 403);

        $view = $this->view_resolver->resolveForRead($item, $this->viewIdParam($request));
        if (! $view) {
            return response()->json(['data' => []]);
        }

        $root_query = $item->items()
            ->where('is_archived', false)
            ->whereNull('parent_id')
            ->whereHas('group', fn ($q) => $q->where('board_view_id', $view->id)->where('is_archived', false));

        return response()->json([
            'data' => $this->filter_service->rootIdsWithMatchingUpdates($root_query, $validated['q']),
        ]);
    }

    /**
     * GET /api/boards/{item}/items/comment-counts
     *
     * Every item's updates counter on the board at once: `counts` maps item ids to their number of
     * published updates and replies (items with none are left out), `mentioned_item_ids` lists the
     * items where the viewer is tagged. An open board reads it to catch up after its
     * `board-comments.{id}` socket was down, see {@see BoardItemCommentCounter}.
     */
    public function commentCounts(Request $request, WorkspaceNavigationItem $item, BoardItemCommentCounter $comment_counter): JsonResponse
    {
        return response()->json([
            'data' => $comment_counter->boardSnapshot($item, $request->user()->id),
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

        $board_item->load(['values', 'dependencyLinks', 'group', 'creator', 'checklistItems']);
        $this->attachMirrorValues(collect([$board_item]), $board_item->group->board_view_id, $board_item->parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM);

        return response()->json(new BoardItemDetailResource($board_item));
    }

    /**
     * POST /api/boards/{item}/items
     *
     * `parent_id` is optional: when given, this creates a subitem of that
     * item instead of a top-level row — the subitem's `group_id` is always
     * inherited from its parent (any client-supplied `group_id` is ignored)
     * so a subitem's denormalized group never diverges from its parent's.
     *
     * `after_item_id` is the row menu's "Create new item below": the new row
     * becomes the next sibling of that item (same group and same parent, so
     * `group_id` and `parent_id` are ignored) and every later sibling is
     * shifted down by one to make room, in the same transaction.
     */
    public function store(StoreBoardItemRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();
        $parent_id = $validated['parent_id'] ?? null;
        $after_item = isset($validated['after_item_id']) ? $item->items()->findOrFail($validated['after_item_id']) : null;

        // A new subitem is part of its parent's work, so someone limited to
        // their assigned items can still add subitems to one of them.
        $parent_for_gate = $after_item?->parent_id !== null
            ? BoardItem::find($after_item->parent_id)
            : ($after_item === null && $parent_id !== null ? BoardItem::find($parent_id) : null);

        if ($parent_for_gate !== null && $parent_for_gate->board_id === $item->id) {
            BoardEditGate::authorizeItem($item, $request->user(), $parent_for_gate);
        } else {
            BoardEditGate::authorizeContent($item, $request->user());
        }

        if (! empty($validated['values'])) {
            $this->column_permissions->authorizeEdit($item, $request->user(), array_keys($validated['values']));
        }

        if ($after_item !== null) {
            $parent_id = $after_item->parent_id;
            $group_id = $after_item->group_id;
            $position = $after_item->position + 1;
        } elseif ($parent_id !== null) {
            $parent = BoardItem::where('id', $parent_id)->firstOrFail();
            $group_id = $parent->group_id;
            $position = $validated['position'] ?? $this->nextPosition($item, $group_id, $parent_id);
        } else {
            $group_id = $validated['group_id'];
            $position = $validated['position'] ?? $this->nextPosition($item, $group_id);
        }

        // "Create below" and an explicit `position` (the toolbar's "New item",
        // which lands the row at the top of the first table) both insert in
        // the middle of the sequence, so every sibling at or past that slot
        // shifts down first, like `BoardGroupController::store()` does.
        $should_shift = $after_item !== null || array_key_exists('position', $validated);

        $board_item = DB::transaction(function () use ($item, $request, $validated, $should_shift, $group_id, $parent_id, $position) {
            if ($should_shift) {
                $item->items()
                    ->where('group_id', $group_id)
                    ->where('parent_id', $parent_id)
                    ->where('position', '>=', $position)
                    ->increment('position');
            }

            return $item->items()->create([
                'group_id' => $group_id,
                'parent_id' => $parent_id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'position' => $position,
                'is_priority' => $validated['is_priority'] ?? false,
                'created_by_id' => $request->user()?->id,
            ]);
        });

        $this->assignAutoNumberValues($board_item, $parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM);

        if (! empty($validated['values'])) {
            $this->value_service->sync($item, $board_item, $validated['values'], $request->user(), false);
        }

        $this->activity_logger->logItem(
            $item,
            $request->user(),
            BoardActivityLog::ACTION_ITEM_CREATED,
            $parent_id === null ? 'Created' : 'Created subitem',
            $board_item,
        );

        $this->automation_service->handleItemCreated($board_item, $request->user());

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
        BoardEditGate::authorizeItem($item, $request->user(), $board_item);

        $validated = $request->validated();
        $from_group_id = $board_item->group_id;
        $old_name = (string) $board_item->name;
        $board_item->fill($validated)->save();

        if (array_key_exists('group_id', $validated)) {
            $this->cascadeGroupToDescendants($board_item, $validated['group_id']);
            $this->automation_service->handleItemMoved($board_item->fresh('group'), $from_group_id, $request->user());
        }
        if (array_key_exists('group_id', $validated)) {
            $this->recordGroupMove($board_item, $from_group_id, $request);
        }
        if (array_key_exists('name', $validated) && $old_name !== (string) $board_item->name) {
            $this->automation_service->handleNameChanged($board_item->fresh('group'), $old_name, $request->user());
            $this->activity_service->recordField($board_item, BoardItemActivityService::FIELD_NAME, 'Name', $old_name, (string) $board_item->name, $request->user(), $old_name, (string) $board_item->name);
        }

        return response()->json([
            'message' => 'Item updated successfully.',
            'item' => new BoardItemResource($board_item->fresh('values')),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/items/{board_item}/parent
     *
     * Row menu's "Convert to subitem" / "Convert to item" and a subitem's
     * "Move to item", the one boundary `reorder()` deliberately can't cross.
     * `parent_id` set makes the row a subitem of that item, landing at the end
     * of its subitem list (a root item is converted, a subitem is moved to a
     * different parent); `parent_id` null promotes a subitem back to a root
     * item, landing at the end of the given `group_id`. Cascades the resulting
     * `group_id` onto the moved item's own descendants, same as `update()`.
     *
     * A root item and a subitem read from separate column sets, so when the
     * row crosses that boundary its cell values are carried over to the
     * matching columns of the other set (see
     * {@see BoardItemScopeConversionService}) and its Auto-number columns
     * are assigned again. Everything runs in one transaction.
     */
    public function updateParent(UpdateBoardItemParentRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        BoardEditGate::authorizeItem($item, $request->user(), $board_item);

        $validated = $request->validated();
        $parent = isset($validated['parent_id']) ? $item->items()->findOrFail($validated['parent_id']) : null;

        DB::transaction(fn () => $this->reparentItem($item, $board_item, $parent, $parent ? $parent->group_id : (int) $validated['group_id']));

        return response()->json([
            'message' => 'Item moved successfully.',
            'item' => new BoardItemResource($board_item->fresh('values')),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/items/parent
     *
     * Selection action bar's "Convert" and, for a subitem selection, "Move to".
     * With a `parent_id` every selected row is placed under that item (items
     * become subitems, subitems change parent). With `parent_id` null every
     * selected subitem becomes an item, in `group_id` when given and otherwise
     * in the table its parent lives in, like monday.com's "Convert to item".
     * Runs in one transaction, so a failure never leaves half a selection moved.
     */
    public function bulkUpdateParent(BulkUpdateBoardItemParentRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();
        $ordered_ids = array_map('intval', $validated['item_ids']);
        $board_items = $item->items()->whereIn('id', $ordered_ids)->get()
            ->sortBy(fn (BoardItem $board_item) => array_search($board_item->id, $ordered_ids, true))
            ->values();

        $this->authorizeItems($item, $request, $board_items);

        $parent = isset($validated['parent_id']) ? $item->items()->findOrFail($validated['parent_id']) : null;
        $target_group_id = isset($validated['group_id']) ? (int) $validated['group_id'] : null;

        DB::transaction(function () use ($item, $board_items, $parent, $target_group_id) {
            foreach ($board_items as $board_item) {
                $group_id = $parent?->group_id ?? $target_group_id ?? $board_item->group_id;
                $this->reparentItem($item, $board_item, $parent, $group_id);
            }
        });

        return response()->json([
            'message' => 'Items moved successfully.',
            'items' => BoardItemResource::collection($board_items->map(fn (BoardItem $board_item) => $board_item->fresh('values'))),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/items/restore
     *
     * Undo for the selection action bar's "Archive" and "Delete". Brings every
     * given item back, together with the subitems that were deleted along with
     * it (the ones trashed at the same moment or later, so a subitem deleted on
     * its own earlier stays in the trash).
     */
    public function bulkRestore(BulkBoardItemsRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $targets = BoardItem::withTrashed()->where('board_id', $item->id)->whereIn('id', $request->validated()['item_ids'])->get();
        $this->authorizeItems($item, $request, $targets);

        DB::transaction(function () use ($item, $targets, $request) {
            foreach ($targets as $target) {
                if ($target->trashed()) {
                    $this->restoreTrashedDescendants($target);
                    $target->restore();
                }
                if ($target->is_archived) {
                    $target->update(['is_archived' => false]);
                }

                $this->activity_logger->logItem($item, $request->user(), BoardActivityLog::ACTION_ITEM_RESTORED, 'Restored', $target);
            }
        });

        foreach ($targets as $target) {
            $this->automation_service->handleItemRestored($target->fresh('group'), $request->user());
        }

        return response()->json([
            'message' => 'Items restored successfully.',
        ]);
    }

    /**
     * Moves one row under `$parent` (null makes it an item in `$group_id`),
     * appended after its new siblings. When the row changes level or tab its
     * cell values are re-keyed onto the target columns, see
     * {@see BoardItemScopeConversionService}. Callers wrap it in a transaction.
     */
    private function reparentItem(WorkspaceNavigationItem $item, BoardItem $board_item, ?BoardItem $parent, int $group_id): void
    {
        $parent_id = $parent?->id;
        $position = $this->nextPosition($item, $group_id, $parent_id);

        $board_item->loadMissing('group');
        $source_view_id = $board_item->group->board_view_id;
        $source_scope = $board_item->parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;

        $board_item->update([
            'parent_id' => $parent_id,
            'group_id' => $group_id,
            'position' => $position,
        ]);

        $this->cascadeGroupToDescendants($board_item, $group_id);

        $board_item->load('group');
        $target_view_id = $board_item->group->board_view_id;
        $target_scope = $parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;

        if ($source_scope !== $target_scope || $source_view_id !== $target_view_id) {
            $this->scope_conversion_service->convert($board_item, $source_view_id, $source_scope, $target_view_id, $target_scope);
            $this->assignAutoNumberValues($board_item, $target_scope);
        }
    }

    /**
     * Restores the descendants `deleteSubtree()` trashed together with `$target`.
     * Soft-deleted rows are invisible to `childrenRecursive`, so the tree is
     * walked level by level with `withTrashed()`.
     */
    private function restoreTrashedDescendants(BoardItem $target): void
    {
        $deleted_at = $target->deleted_at;
        $parent_ids = [$target->id];

        while ($parent_ids !== []) {
            $children = BoardItem::withTrashed()->whereIn('parent_id', $parent_ids)->get();
            foreach ($children as $child) {
                if ($child->trashed() && $deleted_at !== null && $child->deleted_at->greaterThanOrEqualTo($deleted_at->copy()->subSeconds(5))) {
                    $child->restore();
                }
            }
            $parent_ids = $children->pluck('id')->all();
        }
    }

    /**
     * PATCH /api/boards/{item}/items/{board_item}/recurrence
     *
     * Row menu's "Set recurring..." popover — schedules `board_item` to
     * auto-recreate itself (see {@see RecurringItemService}),
     * starting one interval from today (today's own row already exists, so
     * the first *recreation* is the next cycle, not this one).
     */
    public function setRecurrence(SetBoardItemRecurrenceRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        BoardEditGate::authorizeItem($item, $request->user(), $board_item);

        $validated = $request->validated();
        $today = Carbon::today();
        $next_run_date = match ($validated['frequency']) {
            BoardItemRecurrence::FREQUENCY_WEEKLY => $today->copy()->addWeeks($validated['interval_count']),
            BoardItemRecurrence::FREQUENCY_MONTHLY => $today->copy()->addMonthsNoOverflow($validated['interval_count']),
            default => $today->copy()->addDays($validated['interval_count']),
        };

        $recurrence = BoardItemRecurrence::updateOrCreate(
            ['board_item_id' => $board_item->id],
            [
                'frequency' => $validated['frequency'],
                'interval_count' => $validated['interval_count'],
                'next_run_date' => $next_run_date->toDateString(),
                'is_enabled' => true,
            ]
        );

        return response()->json([
            'message' => 'Item set to recur successfully.',
            'recurrence' => ['frequency' => $recurrence->frequency, 'interval_count' => $recurrence->interval_count],
        ]);
    }

    /**
     * DELETE /api/boards/{item}/items/{board_item}/recurrence
     *
     * Row menu's "Stop recurring" action.
     */
    public function clearRecurrence(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        BoardEditGate::authorizeItem($item, $request->user(), $board_item);

        BoardItemRecurrence::where('board_item_id', $board_item->id)->delete();

        return response()->json(['message' => 'Item is no longer recurring.']);
    }

    /**
     * PATCH /api/boards/{item}/items/{board_item}/values
     *
     * Inline cell edits — accepts a `{column_id: value}` map.
     */
    public function updateValues(UpdateBoardItemValuesRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        BoardEditGate::authorizeItem($item, $request->user(), $board_item);
        $this->column_permissions->authorizeEdit($item, $request->user(), array_keys($request->validated()['values']));

        $this->value_service->sync($item, $board_item, $request->validated()['values'], $request->user());

        return response()->json([
            'message' => 'Item updated successfully.',
            'item' => new BoardItemResource($board_item->fresh(['values', 'dependencyLinks'])),
            // Items whose dates moved because they depend on this one, see BoardDependencyScheduler.
            'moved_items' => BoardItemResource::collection($this->dependency_scheduler->movedItems($board_item->id)),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/items/{board_item}
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        BoardEditGate::authorizeItem($item, $request->user(), $board_item);

        $this->activity_logger->logItem($item, $request->user(), BoardActivityLog::ACTION_ITEM_TRASHED, 'Deleted', $board_item);
        $this->deleteSubtree($board_item);
        $this->automation_service->handleItemDeleted($board_item, $request->user());

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
        BoardEditGate::authorizeContent($item, $request->user());

        $originals = $item->items()->with(['values', 'childrenRecursive'])->whereIn('id', $request->validated()['item_ids'])->get();
        $with_subitems = $request->boolean('with_subitems', true);

        $duplicates = $originals->map(function (BoardItem $original) use ($item, $request, $with_subitems) {
            $copy = $this->copySubtree(
                $item,
                $original,
                $original->group_id,
                $original->parent_id,
                $this->nextPosition($item, $original->group_id, $original->parent_id),
                $with_subitems
            );

            $this->activity_logger->logItem($item, $request->user(), BoardActivityLog::ACTION_ITEM_DUPLICATED, 'Duplicated', $original, [
                'copy_item_id' => $copy->id,
            ]);

            return $copy;
        });

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
        BoardEditGate::authorizeContent($item, $request->user());

        $validated = $request->validated();
        $group_id = (int) $validated['group_id'];

        $items = $item->items()->whereIn('id', $validated['item_ids'])->get();

        foreach ($items as $board_item) {
            $from_group_id = $board_item->group_id;
            $board_item->update([
                'group_id' => $group_id,
                'position' => $this->nextPosition($item, $group_id),
            ]);
            $this->cascadeGroupToDescendants($board_item, $group_id);
            $this->recordGroupMove($board_item, $from_group_id, $request);
            $this->automation_service->handleItemMoved($board_item->fresh('group'), $from_group_id, $request->user());
        }

        return response()->json([
            'message' => 'Items moved successfully.',
            'items' => BoardItemResource::collection($items->fresh('values')),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/items/values
     *
     * Bulk cell edit, used when a cell is changed on a row that is checked
     * together with other rows (monday.com applies the change to every
     * checked row). Reuses `BoardItemValueService::sync()` per item so each
     * one gets exactly what a single inline cell edit gets (the People column
     * "Assigned you" notification, activity, automations, dependency moves,
     * silently skipping a column that doesn't belong to the item's own scope).
     *
     * With `value`, every item takes that value. With `added`/`removed`, each
     * item's own list keeps its entries, drops `removed` and gains `added`,
     * so adding a person to five checked rows never erases who each row
     * already had.
     */
    public function bulkSetValue(BulkSetColumnValueRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();
        $column_id = (int) $validated['column_id'];
        $items = $item->items()->whereIn('id', $validated['item_ids'])->get();

        $this->authorizeItems($item, $request, $items);
        $this->column_permissions->authorizeEdit($item, $request->user(), [$column_id]);

        $current_values = $request->isMerge()
            ? BoardItemValue::whereIn('item_id', $items->pluck('id'))->where('column_id', $column_id)->pluck('value', 'item_id')
            : collect();

        foreach ($items as $board_item) {
            $value = $request->isMerge()
                ? $this->mergeListValue($current_values->get($board_item->id), $validated['added'] ?? [], $validated['removed'] ?? [])
                : ($validated['value'] ?? null);

            $this->value_service->sync($item, $board_item, [$column_id => $value], $request->user());
        }

        // Items whose dates moved because they depend on the edited ones, see BoardDependencyScheduler.
        $edited_ids = $items->pluck('id')->all();
        $moved_items = $this->dependency_scheduler->movedItems(0)->reject(fn (BoardItem $moved) => in_array($moved->id, $edited_ids, true));

        return response()->json([
            'message' => 'Items updated successfully.',
            'items' => BoardItemResource::collection($items->fresh(['values', 'dependencyLinks'])),
            'moved_items' => BoardItemResource::collection($moved_items->values()),
        ]);
    }

    /**
     * One item's list cell after a bulk merge: its own entries minus `$removed`, then `$added`
     * (entries it already has are not repeated). Ids are compared as strings, since stored
     * lists mix "12" and 12. An emptied list is stored as null, like clearing the cell by hand.
     *
     * @param  array<int, mixed>  $added
     * @param  array<int, mixed>  $removed
     * @return array<int, mixed>|null
     */
    private function mergeListValue(mixed $current, array $added, array $removed): ?array
    {
        $current_list = is_array($current) ? array_values($current) : [];
        $removed_keys = array_map(fn ($entry) => (string) $entry, $removed);

        $next = array_values(array_filter($current_list, fn ($entry) => ! in_array((string) $entry, $removed_keys, true)));
        $next_keys = array_map(fn ($entry) => (string) $entry, $next);

        foreach ($added as $entry) {
            if (! in_array((string) $entry, $next_keys, true)) {
                $next[] = $entry;
                $next_keys[] = (string) $entry;
            }
        }

        return $next === [] ? null : $next;
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
        BoardEditGate::authorizeContent($item, $request->user());

        $validated = $request->validated();
        $touched_ids = [];
        $moved = null;

        DB::transaction(function () use ($item, $validated, &$touched_ids, &$moved) {
            if ($validated['scope'] === 'root') {
                $moved_item = $item->items()->findOrFail($validated['moved_item_id']);
                $this->ensureItemBelongsToBoard($item, $moved_item);

                if ($moved_item->group_id !== (int) $validated['target_group_id']) {
                    $moved = [$moved_item, $moved_item->group_id];
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

        // Run once the new order is committed, so an automation that moves the item again wins.
        if ($moved !== null) {
            $this->recordGroupMove($moved[0], $moved[1], $request);
            $this->automation_service->handleItemMoved($moved[0]->fresh('group'), $moved[1], $request->user());
        }

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
        $this->authorizeItems($item, $request, $item->items()->whereIn('id', $request->validated()['item_ids'])->get());

        $archived_items = $item->items()->whereIn('id', $request->validated()['item_ids'])->where('is_archived', false)->get();
        $item->items()->whereIn('id', $request->validated()['item_ids'])->update(['is_archived' => true]);

        foreach ($archived_items as $archived_item) {
            $this->activity_logger->logItem($item, $request->user(), BoardActivityLog::ACTION_ITEM_ARCHIVED, 'Archived', $archived_item);
            $this->automation_service->handleItemArchived($archived_item, $request->user());
        }

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
        $this->authorizeItems($item, $request, $items);

        foreach ($items as $board_item) {
            $this->activity_logger->logItem($item, $request->user(), BoardActivityLog::ACTION_ITEM_TRASHED, 'Deleted', $board_item);
            $this->deleteSubtree($board_item);
            $this->automation_service->handleItemDeleted($board_item, $request->user());
        }

        return response()->json([
            'message' => 'Items deleted successfully.',
        ]);
    }

    /**
     * Resolves every Mirror column in `$board_view_id`+`$scope` against
     * `$items` and attaches the result (see {@see MirrorColumnResolver}) —
     * skips the extra queries entirely for a tab with no Mirror columns.
     *
     * @param  Collection<int, BoardItem>  $items
     */
    private function attachMirrorValues(Collection $items, int $board_view_id, string $scope): void
    {
        $columns = BoardColumn::where('board_view_id', $board_view_id)->where('scope', $scope)->get(['id', 'type', 'config']);
        $this->mirror_resolver->attach($items, $columns);
    }

    /**
     * Bulk counterpart of {@see BoardEditGate::authorizeItem()}: a member
     * limited to their assigned items may only act on a selection made
     * entirely of those items.
     *
     * @param  Collection<int, BoardItem>  $items
     */
    private function authorizeItems(WorkspaceNavigationItem $item, Request $request, Collection $items): void
    {
        if (BoardEditGate::allowsContent($item, $request->user())) {
            return;
        }

        if ($items->isEmpty()) {
            BoardEditGate::authorizeContent($item, $request->user());
        }

        foreach ($items as $board_item) {
            BoardEditGate::authorizeItem($item, $request->user(), $board_item);
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
     * Assigns every Auto-number column in this item's scope its next value,
     * see {@see BoardItemValueService::assignAutoNumbers()}.
     */
    private function assignAutoNumberValues(BoardItem $board_item, string $scope): void
    {
        $this->value_service->assignAutoNumbers($board_item, $scope);
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
            'is_priority' => $original->is_priority,
            'created_by_id' => $original->created_by_id,
        ]);

        $scope = $parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;

        // An Auto-number column's value is a stable, per-item identity (like
        // monday.com's "Item ID"), not board content — a duplicate gets its
        // own freshly-assigned number below instead of literally cloning the
        // original's, which every other column type does.
        $auto_number_column_ids = BoardColumn::where('board_view_id', $original->group->board_view_id)
            ->where('scope', $scope)
            ->where('type', BoardColumn::TYPE_AUTO_NUMBER)
            ->pluck('id');

        foreach ($original->values as $value) {
            if ($auto_number_column_ids->contains($value->column_id)) {
                continue;
            }
            $copy->values()->create([
                'column_id' => $value->column_id,
                'value' => $value->value,
            ]);
        }

        $this->assignAutoNumberValues($copy, $scope);

        if ($with_children) {
            foreach ($original->childrenRecursive->where('is_archived', false) as $child) {
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
     * Writes a "Moved" entry to the item's activity (and so to the board
     * Activity log) when `$board_item` now sits in a different group than
     * `$from_group_id`. Both group ids are kept, so the move can be undone.
     */
    private function recordGroupMove(BoardItem $board_item, int $from_group_id, Request $request): void
    {
        if ($from_group_id === (int) $board_item->group_id) {
            return;
        }

        $this->activity_service->recordField(
            $board_item,
            BoardItemActivityService::FIELD_GROUP,
            'Group',
            BoardGroup::whereKey($from_group_id)->value('name'),
            BoardGroup::whereKey($board_item->group_id)->value('name'),
            $request->user(),
            $from_group_id,
            $board_item->group_id,
        );
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

    /**
     * The toolbar's Person/Quick/Advanced filter state, sent as a JSON string
     * in `filter_state` (see `boardContentService.getItems`). Anything that is
     * not a JSON object is ignored, so a malformed value simply returns the
     * unfiltered tab.
     *
     * @return array<string, mixed>|null
     */
    private function filterStateParam(Request $request): ?array
    {
        $raw = $request->query('filter_state');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Reads `group_ids[]` from the query string for GET requests — see
     * `index()`'s own doc comment. Empty/absent returns `[]`, meaning "every
     * table in the tab" (no additional `whereIn` filter applied).
     *
     * @return array<int, int>
     */
    private function groupIdsParam(Request $request): array
    {
        $group_ids = $request->query('group_ids');

        return is_array($group_ids) ? array_map('intval', $group_ids) : [];
    }

    /**
     * Reads `item_ids[]` from the query string, see `index()`. Empty or absent returns `[]`.
     *
     * @return array<int, int>
     */
    private function itemIdsParam(Request $request): array
    {
        $item_ids = $request->query('item_ids');

        return is_array($item_ids) ? array_slice(array_values(array_unique(array_map('intval', $item_ids))), 0, 500) : [];
    }
}
