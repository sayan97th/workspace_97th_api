<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Resources\BoardActivityLogResource;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The board options menu's "Activity log" item — a board-level timeline (see
 * {@see \App\Models\BoardActivityLog} for exactly what it tracks).
 */
class BoardActivityLogController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

    /**
     * GET /api/boards/{item}/activity-log
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $per_page = max(1, min((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));

        $entries = $item->activityLogs()->with('user')->paginate($per_page);

        return response()->json([
            'data' => BoardActivityLogResource::collection($entries->items()),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
        ]);
    }
}
