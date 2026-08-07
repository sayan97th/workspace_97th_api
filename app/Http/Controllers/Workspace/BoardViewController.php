<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Resources\BoardViewSummaryResource;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cross-board board-view listings that power Manage Workspace's "Recents"
 * and "Content" tabs. Every other board-view endpoint
 * ({@link \App\Http\Controllers\Board\BoardViewController}) is scoped to a
 * single board — these are the only ones that query {@link BoardView} across
 * a whole workspace (or the whole app).
 */
class BoardViewController extends Controller
{
    /** Hard ceiling on the app-wide listing so one giant account can't return everything at once. */
    private const MAX_LIMIT = 200;

    /**
     * GET /api/workspaces/{workspace}/board-views/recent
     *
     * The most recently created board views (tabs) within this workspace —
     * the "Recents" tab.
     */
    public function recent(Request $request, Workspace $workspace): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', 10), self::MAX_LIMIT));

        $views = BoardView::query()
            ->whereHas('board', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with(['creator', 'board.workspace'])
            ->latest('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => BoardViewSummaryResource::collection($views),
        ]);
    }

    /**
     * GET /api/board-views
     *
     * Every board view (tab) across every workspace the current user belongs
     * to, newest first — the "Content" tab.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workspace_ids = $user->workspaces()->pluck('workspaces.id');

        $views = BoardView::query()
            ->whereHas('board', fn ($query) => $query->whereIn('workspace_id', $workspace_ids))
            ->with(['creator', 'board.workspace'])
            ->latest('created_at')
            ->limit(self::MAX_LIMIT)
            ->get();

        return response()->json([
            'data' => BoardViewSummaryResource::collection($views),
        ]);
    }
}
