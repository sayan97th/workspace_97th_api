<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardItemChecklistItemRequest;
use App\Http\Requests\Board\UpdateBoardItemChecklistItemRequest;
use App\Http\Resources\BoardItemChecklistItemResource;
use App\Models\BoardItem;
use App\Models\BoardItemChecklistItem;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;

/**
 * The subtask checklist lines shown under a Kanban card's "Subtasks" section
 * in the item drawer, and rolled up into the card's "✓ done/total" badge
 * (see `BoardItemResource::checklist_total_count`/`checklist_done_count`).
 */
class BoardItemChecklistItemController extends Controller
{
    /**
     * POST /api/boards/{item}/items/{board_item}/checklist-items
     */
    public function store(StoreBoardItemChecklistItemRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);

        $checklist_item = $board_item->checklistItems()->create([
            'label' => $request->validated()['label'],
            'position' => $this->nextPosition($board_item),
        ]);

        return response()->json([
            'message' => 'Subtask added successfully.',
            'checklist_item' => new BoardItemChecklistItemResource($checklist_item->fresh()),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/items/{board_item}/checklist-items/{checklist_item}
     *
     * Renames the subtask and/or toggles it done, depending on which fields
     * are present.
     */
    public function update(
        UpdateBoardItemChecklistItemRequest $request,
        WorkspaceNavigationItem $item,
        BoardItem $board_item,
        BoardItemChecklistItem $checklist_item
    ): JsonResponse {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureChecklistItemBelongsToItem($board_item, $checklist_item);

        $checklist_item->fill($request->validated())->save();

        return response()->json([
            'message' => 'Subtask updated successfully.',
            'checklist_item' => new BoardItemChecklistItemResource($checklist_item->fresh()),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/items/{board_item}/checklist-items/{checklist_item}
     */
    public function destroy(WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemChecklistItem $checklist_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureChecklistItemBelongsToItem($board_item, $checklist_item);

        $checklist_item->delete();

        return response()->json([
            'message' => 'Subtask deleted successfully.',
        ]);
    }

    /**
     * Guard: abort with 404 when the item is not part of the board.
     */
    private function ensureItemBelongsToBoard(WorkspaceNavigationItem $item, BoardItem $board_item): void
    {
        abort_if($board_item->board_id !== $item->id, 404);
    }

    /**
     * Guard: abort with 404 when the checklist item is not part of the item.
     */
    private function ensureChecklistItemBelongsToItem(BoardItem $board_item, BoardItemChecklistItem $checklist_item): void
    {
        abort_if($checklist_item->item_id !== $board_item->id, 404);
    }

    /**
     * The next free position among an item's checklist lines (append to the end).
     */
    private function nextPosition(BoardItem $board_item): int
    {
        return (int) $board_item->checklistItems()->max('position') + 1;
    }
}
