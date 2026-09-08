<?php

namespace App\Http\Controllers\Board;

use App\Enums\BoardViewType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardViewRequest;
use App\Http\Requests\Board\UpdateBoardViewRequest;
use App\Http\Requests\Board\UpdatePersonalViewOrderRequest;
use App\Http\Resources\BoardViewResource;
use App\Models\BoardView;
use App\Models\BoardViewUserOrder;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardDuplicationService;
use App\Services\Board\ChartDataService;
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
        $this->ensurePrimaryViewExists($item);

        $personal_order = BoardViewUserOrder::where('user_id', $request->user()?->id)
            ->where('board_id', $item->id)
            ->value('view_order');

        return response()->json([
            'data' => BoardViewResource::collection($item->views()->with('creator')->get()),
            'personal_order' => $personal_order,
        ]);
    }

    /**
     * Guarantees every board has a primary tab to scope its content into —
     * created lazily on first `views` load rather than at board-creation
     * time, so boards created before per-tab content scoping existed still
     * get one. Content-index endpoints (`BoardColumnController`,
     * `BoardGroupController`, `BoardItemController`) resolve to an empty
     * list until this has run at least once for a given board.
     *
     * Deliberately does not seed any starter `BoardColumn` rows: the item's
     * own name is a built-in field, not a column, so a brand-new tab has no
     * columns at all until the user explicitly adds one (see
     * {@see BoardColumnController::store()}) or imports a file that creates
     * them (see {@see BoardImportController}).
     */
    private function ensurePrimaryViewExists(WorkspaceNavigationItem $item): void
    {
        if ($item->views()->where('is_primary', true)->exists()) {
            return;
        }

        $item->views()->create([
            'label' => 'Main table',
            'view_type' => BoardViewType::Table->value,
            'position' => 0,
            'is_primary' => true,
            'row_height' => 'single',
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
            'view_type' => $validated['view_type'] ?? BoardViewType::Table->value,
            'position' => $validated['position'] ?? $this->nextPosition($item),
            'is_primary' => $validated['is_primary'] ?? false,
            'row_height' => $validated['row_height'] ?? 'single',
            'created_by_id' => $request->user()?->id,
        ]);
        $view->setRelation('creator', $request->user());

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
            'view' => new BoardViewResource($board_view->fresh(['creator'])),
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
     * new, always-unlocked, non-primary tab appended to the end — and,
     * because columns/groups/items are scoped per tab (see
     * `BelongsToBoardView`), also deep-clones every table (group), column,
     * item and cell value the source tab owns, so the new tab is a genuine,
     * independently-editable copy rather than an empty shell. Comments/
     * activity on the source items are intentionally NOT copied — a
     * duplicate is a fresh structural copy to edit, not a copy of the
     * conversation history.
     */
    public function duplicate(Request $request, WorkspaceNavigationItem $item, BoardView $board_view, BoardDuplicationService $duplication_service): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);
        $this->ensureViewUnlocked($board_view);

        $copy = $duplication_service->duplicateView(
            $board_view,
            $item,
            ['label' => "{$board_view->label} (copy)", 'position' => $this->nextPosition($item)],
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'View duplicated successfully.',
            'view' => new BoardViewResource($copy->fresh(['creator'])),
        ], 201);
    }

    /**
     * GET /api/boards/{item}/views/{board_view}/chart-data
     *
     * Computed chart series for a `chart`-type view — see
     * {@see ChartDataService} for how a chart tab's own (empty) content is
     * bypassed in favor of aggregating another tab's items.
     */
    public function chartData(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);

        return response()->json((new ChartDataService)->build($item, $board_view));
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
            'view' => new BoardViewResource($board_view->load('creator')),
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
            'view' => new BoardViewResource($board_view->load('creator')),
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
