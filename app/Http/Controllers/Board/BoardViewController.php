<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardViewRequest;
use App\Http\Requests\Board\UpdateBoardViewRequest;
use App\Http\Requests\Board\UpdatePersonalViewOrderRequest;
use App\Http\Resources\BoardViewResource;
use App\Models\BoardView;
use App\Models\BoardViewUserOrder;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardViewController extends Controller
{
    /**
     * GET /api/boards/{item}/views
     *
     * A view row is both a tab (`label`/`position`) and a saved filter
     * configuration for that tab (`filter_state`/`sort_state`/etc).
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $personal_order = BoardViewUserOrder::where('user_id', $request->user()?->id)
            ->where('board_id', $item->id)
            ->value('view_order');

        return response()->json([
            'data' => BoardViewResource::collection($item->views),
            'personal_order' => $personal_order,
        ]);
    }

    /**
     * POST /api/boards/{item}/views
     */
    public function store(StoreBoardViewRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();

        $view = $item->views()->create([
            ...$validated,
            'position' => $validated['position'] ?? $this->nextPosition($item),
            'is_primary' => $validated['is_primary'] ?? false,
            'row_height' => $validated['row_height'] ?? 'single',
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'View created successfully.',
            'view' => new BoardViewResource($view),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/views/{board_view}
     *
     * This is the "save filters for this board view" endpoint — called with
     * any subset of the saved-state fields that changed.
     */
    public function update(UpdateBoardViewRequest $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);
        $this->ensureViewUnlocked($board_view);

        $board_view->fill($request->validated())->save();

        return response()->json([
            'message' => 'View saved successfully.',
            'view' => new BoardViewResource($board_view->fresh()),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/views/{board_view}
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);
        $this->ensureViewUnlocked($board_view);

        if ($board_view->is_primary) {
            return response()->json([
                'message' => 'The primary view cannot be deleted.',
            ], 422);
        }

        $board_view->delete();

        return response()->json([
            'message' => 'View deleted successfully.',
        ]);
    }

    /**
     * POST /api/boards/{item}/views/{board_view}/duplicate
     *
     * Clones a view's label + saved filter/sort/display configuration into a
     * new, always-unlocked, non-primary tab appended to the end.
     */
    public function duplicate(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);
        $this->ensureViewUnlocked($board_view);

        $copy = $item->views()->create([
            'label' => "{$board_view->label} (copy)",
            'icon' => $board_view->icon,
            'position' => $this->nextPosition($item),
            'is_primary' => false,
            'pinned' => false,
            'is_locked' => false,
            'locked_by_id' => null,
            'filter_state' => $board_view->filter_state,
            'sort_state' => $board_view->sort_state,
            'group_by_option_id' => $board_view->group_by_option_id,
            'hidden_column_ids' => $board_view->hidden_column_ids,
            'pinned_column_ids' => $board_view->pinned_column_ids,
            'row_height' => $board_view->row_height,
            'conditional_color_rules' => $board_view->conditional_color_rules,
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'View duplicated successfully.',
            'view' => new BoardViewResource($copy),
        ], 201);
    }

    /**
     * POST /api/boards/{item}/views/{board_view}/pin
     *
     * Toggles whether the tab is pinned (sorts ahead of unpinned tabs).
     * Allowed even while locked — pinning doesn't touch the view's saved
     * filter content.
     */
    public function togglePin(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);

        $board_view->update(['pinned' => ! $board_view->pinned]);

        return response()->json([
            'message' => $board_view->pinned ? 'View pinned successfully.' : 'View unpinned successfully.',
            'view' => new BoardViewResource($board_view),
        ]);
    }

    /**
     * POST /api/boards/{item}/views/{board_view}/lock
     *
     * Toggles the view's lock. While locked, rename/delete/duplicate and
     * saving filter/sort/display changes are all blocked (see `update()`,
     * `destroy()`, `duplicate()`) until any collaborator unlocks it again.
     */
    public function toggleLock(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);

        $is_locked = ! $board_view->is_locked;
        $board_view->update([
            'is_locked' => $is_locked,
            'locked_by_id' => $is_locked ? $request->user()?->id : null,
        ]);

        return response()->json([
            'message' => $is_locked ? 'View locked successfully.' : 'View unlocked successfully.',
            'view' => new BoardViewResource($board_view),
        ]);
    }

    /**
     * PUT /api/boards/{item}/views/order
     *
     * Saves the authenticated user's personal "Reorder (for you only)" tab
     * order for this board — doesn't touch the shared `position`/`pinned`
     * columns other collaborators see.
     */
    public function updatePersonalOrder(UpdatePersonalViewOrderRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $board_view_ids = $item->views()->pluck('id');
        $view_order = collect($request->validated('view_ids'))
            ->filter(fn (int $id) => $board_view_ids->contains($id))
            ->values()
            ->all();

        $order = BoardViewUserOrder::updateOrCreate(
            ['user_id' => $request->user()?->id, 'board_id' => $item->id],
            ['view_order' => $view_order],
        );

        return response()->json([
            'message' => 'View order saved successfully.',
            'personal_order' => $order->view_order,
        ]);
    }

    /**
     * Guard: abort with 404 when the view is not part of the board.
     */
    private function ensureViewBelongsToBoard(WorkspaceNavigationItem $item, BoardView $board_view): void
    {
        abort_if($board_view->board_id !== $item->id, 404);
    }

    /**
     * Guard: abort with 423 (Locked) when the view is locked — blocks
     * rename/delete/duplicate and saving filter/sort/display changes.
     */
    private function ensureViewUnlocked(BoardView $board_view): void
    {
        abort_if($board_view->is_locked, 423, 'This view is locked and can\'t be edited. Unlock it first.');
    }

    /**
     * The next free position among the board's views (append to the end).
     */
    private function nextPosition(WorkspaceNavigationItem $item): int
    {
        return (int) $item->views()->max('position') + 1;
    }
}
