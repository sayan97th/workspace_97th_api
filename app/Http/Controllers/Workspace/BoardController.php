<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Resources\BoardResource;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;

class BoardController extends Controller
{
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
        $item->load(['creator', 'workspace.owners']);

        return response()->json(new BoardResource($item));
    }

    /**
     * GET /api/boards/client-hub
     *
     * Client Hub is rendered by the frontend at the static `/client-hub` route
     * (not the id-routed `/boards/{id}` page), so it never receives its own
     * navigation item id as a route param. This resolves the singleton Client
     * Hub leaf (seeded with `view_key = 'client_hub'`) so the frontend can use
     * its id as the `board_id` for the shared `boards/{item}/views` endpoints
     * — reusing the generic saved-views engine for Client Hub's tabs without
     * touching its mock-data table content.
     */
    public function showClientHub(): JsonResponse
    {
        $item = WorkspaceNavigationItem::where('view_key', 'client_hub')->firstOrFail();
        $item->load(['creator', 'workspace.owners']);

        return response()->json(new BoardResource($item));
    }
}
