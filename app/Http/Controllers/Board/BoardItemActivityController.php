<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Resources\BoardItemActivityResource;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardItemActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The item card's "Activity Log" tab: every recorded change to the item's
 * cells (status, people, dates, text and so on), newest first. The entries
 * are written by {@see BoardItemActivityService}.
 */
class BoardItemActivityController extends Controller
{
    /** Most entries returned per page. */
    private const PER_PAGE = 50;

    /**
     * GET /api/boards/{item}/items/{board_item}/activity
     *
     * Optional `column_id` narrows the log to one column, optional `user_id`
     * to one person. Paginated with `page`.
     */
    public function index(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        abort_if($board_item->board_id !== $item->id, 404);

        $validated = $request->validate([
            'column_id' => ['sometimes', 'integer'],
            'user_id' => ['sometimes', 'integer'],
        ]);

        $page = $board_item->activities()
            ->with('user')
            ->when(isset($validated['column_id']), fn ($query) => $query->where('column_id', $validated['column_id']))
            ->when(isset($validated['user_id']), fn ($query) => $query->where('user_id', $validated['user_id']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => BoardItemActivityResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
