<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceContentItemResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage Workspace's "Recents" and "Content" tabs — both list the same kind
 * of row the sidebar does (board/doc leaves), just sliced differently: the
 * newest few within one workspace, versus every one across every workspace
 * the user belongs to.
 */
class ContentController extends Controller
{
    private const DEFAULT_RECENT_LIMIT = 10;

    private const MAX_RECENT_LIMIT = 50;

    private const DEFAULT_PER_PAGE = 30;

    private const MAX_PER_PAGE = 100;

    /**
     * GET /api/workspaces/{workspace}/content/recent
     *
     * The most recently created boards/docs within this workspace, at any
     * depth in its navigation tree.
     */
    public function recent(Request $request, Workspace $workspace): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', self::DEFAULT_RECENT_LIMIT), self::MAX_RECENT_LIMIT));

        $items = $workspace->navigationItems()
            ->where('type', WorkspaceNavigationItem::TYPE_LEAF)
            ->with(['creator', 'workspace'])
            ->latest('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => WorkspaceContentItemResource::collection($items),
        ]);
    }

    /**
     * GET /api/content
     *
     * Every board/doc across every workspace the current user belongs to,
     * paginated — the exact same rows their sidebar can navigate to.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workspace_ids = $user->workspaces()->pluck('workspaces.id');
        $per_page = max(1, min((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));

        $paginator = WorkspaceNavigationItem::query()
            ->whereIn('workspace_id', $workspace_ids)
            ->where('type', WorkspaceNavigationItem::TYPE_LEAF)
            ->with(['creator', 'workspace'])
            ->orderBy('label')
            ->paginate($per_page);

        return response()->json([
            'data' => WorkspaceContentItemResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
