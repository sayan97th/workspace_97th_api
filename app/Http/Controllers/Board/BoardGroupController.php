<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\MoveBoardGroupRequest;
use App\Http\Requests\Board\StoreBoardGroupRequest;
use App\Http\Requests\Board\UpdateBoardGroupRequest;
use App\Http\Resources\BoardGroupResource;
use App\Models\BoardGroup;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;

class BoardGroupController extends Controller
{
    /**
     * GET /api/boards/{item}/groups
     *
     * A board's groups are its "tables" — any number (1…N) can exist, each
     * rendered as its own titled table by the frontend's `BoardTable`.
     */
    public function index(WorkspaceNavigationItem $item): JsonResponse
    {
        return response()->json([
            'data' => BoardGroupResource::collection($item->groups),
        ]);
    }

    /**
     * POST /api/boards/{item}/groups
     */
    public function store(StoreBoardGroupRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();

        $group = $item->groups()->create([
            'name' => $validated['name'],
            'accent_color' => $validated['accent_color'] ?? '#579bfc',
            'position' => $validated['position'] ?? $this->nextPosition($item),
        ]);

        return response()->json([
            'message' => 'Table created successfully.',
            'group' => new BoardGroupResource($group),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/groups/{group}
     */
    public function update(UpdateBoardGroupRequest $request, WorkspaceNavigationItem $item, BoardGroup $group): JsonResponse
    {
        $this->ensureGroupBelongsToBoard($item, $group);

        $group->fill($request->validated())->save();

        return response()->json([
            'message' => 'Table updated successfully.',
            'group' => new BoardGroupResource($group->fresh()),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/groups/{group}/move
     */
    public function move(MoveBoardGroupRequest $request, WorkspaceNavigationItem $item, BoardGroup $group): JsonResponse
    {
        $this->ensureGroupBelongsToBoard($item, $group);

        $group->position = $request->validated()['position'];
        $group->save();

        return response()->json([
            'message' => 'Table moved successfully.',
            'group' => new BoardGroupResource($group->fresh()),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/groups/{group}
     *
     * Cascades to the group's items (see the `board_items` migration).
     */
    public function destroy(WorkspaceNavigationItem $item, BoardGroup $group): JsonResponse
    {
        $this->ensureGroupBelongsToBoard($item, $group);

        $group->delete();

        return response()->json([
            'message' => 'Table deleted successfully.',
        ]);
    }

    /**
     * Guard: abort with 404 when the group is not part of the board.
     */
    private function ensureGroupBelongsToBoard(WorkspaceNavigationItem $item, BoardGroup $group): void
    {
        abort_if($group->board_id !== $item->id, 404);
    }

    /**
     * The next free position among the board's groups (append to the end).
     */
    private function nextPosition(WorkspaceNavigationItem $item): int
    {
        return (int) $item->groups()->max('position') + 1;
    }
}
