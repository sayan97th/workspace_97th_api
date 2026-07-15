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
}
