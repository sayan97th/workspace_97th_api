<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\BulkBoardItemsRequest;
use App\Http\Requests\Board\BulkMoveBoardItemsRequest;
use App\Http\Requests\Board\StoreBoardItemRequest;
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
     * Returns every item in the tab (`view_id` if given, otherwise the
     * board's primary tab) with its values, optionally narrowed by a
     * `search` term. An item's tab is derived through its group
     * (`board_groups.board_view_id`), since every item requires a group.
     * Grouping/sorting/hiding/coloring is derived client-side by
     * `useBoardToolbar` from this full set — see the plan's "filter
     * execution model" note.
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $view = $this->view_resolver->resolveForRead($item, $this->viewIdParam($request));

        if (! $view) {
            return response()->json(['data' => []]);
        }

        $query = $item->items()
            ->where('is_archived', false)
            ->whereHas('group', fn ($q) => $q->where('board_view_id', $view->id))
            ->with('values')
            ->withCount([
                'comments',
                'commentAttachments',
                'checklistItems as checklist_total_count',
                'checklistItems as checklist_done_count' => fn ($q) => $q->where('is_done', true),
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
     */
    public function store(StoreBoardItemRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();

        $board_item = $item->items()->create([
            'group_id' => $validated['group_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'position' => $validated['position'] ?? $this->nextPosition($item, $validated['group_id']),
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
     * Renames the item and/or moves it to a different group.
     */
    public function update(UpdateBoardItemRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);

        $board_item->fill($request->validated())->save();

        return response()->json([
            'message' => 'Item updated successfully.',
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

        $board_item->delete();

        return response()->json([
            'message' => 'Item deleted successfully.',
        ]);
    }

    /**
     * POST /api/boards/{item}/items/duplicate
     *
     * Selection action bar's "Duplicate" — copies each given item's name,
     * description and column values into its own original group, appended
     * after that group's existing rows.
     */
    public function bulkDuplicate(BulkBoardItemsRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $originals = $item->items()->with('values')->whereIn('id', $request->validated()['item_ids'])->get();

        $duplicates = $originals->map(function (BoardItem $original) use ($item) {
            $duplicate = $item->items()->create([
                'group_id' => $original->group_id,
                'name' => "{$original->name} (copy)",
                'description' => $original->description,
                'position' => $this->nextPosition($item, $original->group_id),
                'created_by_id' => $original->created_by_id,
            ]);

            foreach ($original->values as $value) {
                $duplicate->values()->create([
                    'column_id' => $value->column_id,
                    'value' => $value->value,
                ]);
            }

            return $duplicate->fresh('values');
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
        }

        return response()->json([
            'message' => 'Items moved successfully.',
            'items' => BoardItemResource::collection($items->fresh('values')),
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
     * soft-deletes every given item.
     */
    public function bulkDestroy(BulkBoardItemsRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $item->items()->whereIn('id', $request->validated()['item_ids'])->delete();

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
        $valid_columns = BoardColumn::where('board_view_id', $board_item->group->board_view_id)
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
     */
    private function nextPosition(WorkspaceNavigationItem $item, int $group_id): int
    {
        return (int) $item->items()->where('group_id', $group_id)->max('position') + 1;
    }

    /**
     * Reads `view_id` from the query string for GET requests.
     */
    private function viewIdParam(Request $request): ?int
    {
        return $request->filled('view_id') ? (int) $request->query('view_id') : null;
    }
}
