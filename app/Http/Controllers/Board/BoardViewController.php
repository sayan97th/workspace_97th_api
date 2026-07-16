<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardViewRequest;
use App\Http\Requests\Board\UpdateBoardViewRequest;
use App\Http\Resources\BoardViewResource;
use App\Models\BoardView;
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
    public function index(WorkspaceNavigationItem $item): JsonResponse
    {
        return response()->json([
            'data' => BoardViewResource::collection($item->views),
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
     * Guard: abort with 404 when the view is not part of the board.
     */
    private function ensureViewBelongsToBoard(WorkspaceNavigationItem $item, BoardView $board_view): void
    {
        abort_if($board_view->board_id !== $item->id, 404);
    }

    /**
     * The next free position among the board's views (append to the end).
     */
    private function nextPosition(WorkspaceNavigationItem $item): int
    {
        return (int) $item->views()->max('position') + 1;
    }
}
