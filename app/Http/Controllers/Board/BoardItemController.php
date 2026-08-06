<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardItemRequest;
use App\Http\Requests\Board\UpdateBoardItemRequest;
use App\Http\Requests\Board\UpdateBoardItemValuesRequest;
use App\Http\Resources\BoardItemDetailResource;
use App\Http\Resources\BoardItemResource;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardItemFilterService;
use App\Services\Board\BoardViewResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardItemController extends Controller
{
    public function __construct(
        private readonly BoardItemFilterService $filter_service,
        private readonly BoardViewResolver $view_resolver,
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
            ->whereHas('group', fn ($q) => $q->where('board_view_id', $view->id))
            ->with('values')->withCount(['comments', 'commentAttachments'])->orderBy('group_id')->orderBy('position');

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

        $board_item->load(['values', 'group', 'creator']);

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
            $this->syncValues($item, $board_item, $validated['values']);
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

        $this->syncValues($item, $board_item, $request->validated()['values']);

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
     * Upserts one {@link \App\Models\BoardItemValue} per entry in `$values`,
     * silently skipping any column id that doesn't belong to this item's own
     * tab (columns are per-tab, so a column from a different tab of the same
     * board is rejected too, not just columns from other boards).
     *
     * @param  array<string, mixed>  $values
     */
    private function syncValues(WorkspaceNavigationItem $item, BoardItem $board_item, array $values): void
    {
        $valid_column_ids = BoardColumn::where('board_view_id', $board_item->group->board_view_id)->pluck('id')->all();

        foreach ($values as $column_id => $value) {
            if (! in_array((int) $column_id, $valid_column_ids, true)) {
                continue;
            }

            $board_item->values()->updateOrCreate(
                ['column_id' => (int) $column_id],
                ['value' => $value]
            );
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
