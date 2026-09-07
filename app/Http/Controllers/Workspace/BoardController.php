<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Resources\BoardResource;
use App\Models\BoardActivityLog;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardActivityLogger;
use App\Support\BoardManagementGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardController extends Controller
{
    public function __construct(private readonly BoardActivityLogger $activity_logger) {}

    /**
     * GET /api/boards/{item}
     *
     * Resolves a single navigation item by its globally-unique id. Every item's
     * `workspace_id` says which workspace it belongs to, so a deep link never
     * needs to carry the workspace slug — this is what `/boards/{id}` routes
     * resolve against on the frontend.
     */
    public function show(WorkspaceNavigationItem $item): JsonResponse
    {
        $item->load(['creator', 'workspace.owners'])->loadCount('comments');

        return response()->json(new BoardResource($item));
    }

    /**
     * POST /api/boards/{item}/archive
     *
     * Board options menu's "Archive board" — hides the board from the
     * sidebar/nav tree and the workspace's Content tab without deleting it
     * (unlike "Delete board", which soft-deletes it — see
     * {@see \App\Http\Controllers\Workspace\WorkspaceNavigationItemController::destroy()}).
     * Reachable again from "View archive / trash" > Archive.
     */
    public function archive(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        BoardManagementGate::authorize($item, $request->user(), 'archive this board');

        $item->update(['is_archived' => true, 'archived_at' => now()]);
        $this->activity_logger->log($item, $request->user(), BoardActivityLog::ACTION_ARCHIVED, 'Archived the board');

        return response()->json([
            'message' => 'Board archived successfully.',
            'item' => new BoardResource($item->fresh()->load(['creator', 'workspace.owners'])),
        ]);
    }

    /**
     * POST /api/boards/{item}/unarchive
     *
     * "View archive / trash" > Archive tab's "Restore".
     */
    public function unarchive(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        BoardManagementGate::authorize($item, $request->user(), 'restore this board');

        $item->update(['is_archived' => false, 'archived_at' => null]);
        $this->activity_logger->log($item, $request->user(), BoardActivityLog::ACTION_UNARCHIVED, 'Restored the board from the archive');

        return response()->json([
            'message' => 'Board restored successfully.',
            'item' => new BoardResource($item->fresh()->load(['creator', 'workspace.owners'])),
        ]);
    }
}
