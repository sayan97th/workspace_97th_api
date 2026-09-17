<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardTagRequest;
use App\Http\Requests\Board\UpdateBoardTagRequest;
use App\Http\Resources\BoardTagResource;
use App\Models\BoardTag;
use App\Models\WorkspaceNavigationItem;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Tags column's board-wide option list (`#label` + color) — see
 * {@see \App\Models\BoardTag}'s own doc comment for why this is board-wide
 * rather than per-column like Status/Dropdown's `board_columns.config.options`.
 */
class BoardTagController extends Controller
{
    /**
     * GET /api/boards/{item}/tags
     */
    public function index(WorkspaceNavigationItem $item): JsonResponse
    {
        $tags = $item->tags()->orderBy('position')->get();

        return response()->json([
            'data' => BoardTagResource::collection($tags),
        ]);
    }

    /**
     * POST /api/boards/{item}/tags
     *
     * Fired from a Tags cell's own "Create new tag" or from the column's
     * "Manage tags" modal — either way it lands at the end of the board's
     * shared tag list.
     */
    public function store(StoreBoardTagRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        BoardEditGate::authorize($item, $request->user());

        $validated = $request->validated();

        $tag = $item->tags()->create([
            'label' => $validated['label'],
            'color' => $validated['color'],
            'position' => $this->nextPosition($item),
        ]);

        return response()->json([
            'message' => 'Tag created successfully.',
            'tag' => new BoardTagResource($tag),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/tags/{tag}
     */
    public function update(UpdateBoardTagRequest $request, WorkspaceNavigationItem $item, BoardTag $tag): JsonResponse
    {
        BoardEditGate::authorize($item, $request->user());
        $this->ensureTagBelongsToBoard($item, $tag);

        $tag->fill($request->validated())->save();

        return response()->json([
            'message' => 'Tag updated successfully.',
            'tag' => new BoardTagResource($tag->fresh()),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/tags/{tag}
     *
     * Permanently removes the tag from the board's shared list — every cell
     * that had it selected simply drops it from its own value the next time
     * that item is saved, mirroring a deleted Status/Dropdown option.
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardTag $tag): JsonResponse
    {
        BoardEditGate::authorize($item, $request->user());
        $this->ensureTagBelongsToBoard($item, $tag);

        $tag->delete();

        return response()->json([
            'message' => 'Tag deleted successfully.',
        ]);
    }

    /**
     * Guard: abort with 404 when the tag is not part of the board.
     */
    private function ensureTagBelongsToBoard(WorkspaceNavigationItem $item, BoardTag $tag): void
    {
        abort_if($tag->board_id !== $item->id, 404);
    }

    /**
     * The next free position among the board's tags (append to the end).
     */
    private function nextPosition(WorkspaceNavigationItem $item): int
    {
        return (int) $item->tags()->max('position') + 1;
    }
}
