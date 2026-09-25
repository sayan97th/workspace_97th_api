<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardSavedFilterRequest;
use App\Http\Requests\Board\UpdateBoardSavedFilterRequest;
use App\Models\BoardSavedFilter;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Filter panel's "Saved filters": personal, named filters for one board.
 * Every action is scoped to the requesting user, so nobody can list, change or
 * delete someone else's saved filters, and saving one never touches the
 * board's shared views.
 */
class BoardSavedFilterController extends Controller
{
    /** Keeps the menu usable and a single user from piling up rows. */
    private const MAX_SAVED_FILTERS_PER_BOARD = 50;

    /**
     * GET /api/boards/{item}/saved-filters
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $this->ensureWorkspaceMember($request, $item);

        $saved_filters = BoardSavedFilter::query()
            ->where('user_id', $request->user()->id)
            ->where('board_id', $item->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $saved_filters->map(fn (BoardSavedFilter $saved_filter) => $saved_filter->toPayload())->values(),
        ]);
    }

    /**
     * POST /api/boards/{item}/saved-filters
     */
    public function store(StoreBoardSavedFilterRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $this->ensureWorkspaceMember($request, $item);

        $count = BoardSavedFilter::query()->where('user_id', $request->user()->id)->where('board_id', $item->id)->count();
        abort_if($count >= self::MAX_SAVED_FILTERS_PER_BOARD, 422, 'You have reached the limit of saved filters for this board.');

        $saved_filter = BoardSavedFilter::create([
            'user_id' => $request->user()->id,
            'board_id' => $item->id,
            'name' => trim($request->validated('name')),
            'filter_state' => $request->validated('filter_state'),
        ]);

        return response()->json([
            'message' => 'Filter saved successfully.',
            'saved_filter' => $saved_filter->toPayload(),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/saved-filters/{saved_filter}
     */
    public function update(UpdateBoardSavedFilterRequest $request, WorkspaceNavigationItem $item, BoardSavedFilter $saved_filter): JsonResponse
    {
        $this->ensureOwnSavedFilter($request, $item, $saved_filter);

        $saved_filter->update(['name' => trim($request->validated('name'))]);

        return response()->json([
            'message' => 'Filter renamed successfully.',
            'saved_filter' => $saved_filter->toPayload(),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/saved-filters/{saved_filter}
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardSavedFilter $saved_filter): JsonResponse
    {
        $this->ensureOwnSavedFilter($request, $item, $saved_filter);

        $saved_filter->delete();

        return response()->json(['message' => 'Filter deleted successfully.']);
    }

    private function ensureWorkspaceMember(Request $request, WorkspaceNavigationItem $item): void
    {
        abort_unless($request->user()->workspaces()->where('workspaces.id', $item->workspace_id)->exists(), 403);
    }

    /**
     * Someone else's saved filter answers 404, so its existence is not revealed.
     */
    private function ensureOwnSavedFilter(Request $request, WorkspaceNavigationItem $item, BoardSavedFilter $saved_filter): void
    {
        abort_if($saved_filter->board_id !== $item->id || $saved_filter->user_id !== $request->user()->id, 404);
    }
}
