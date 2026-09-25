<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\MoveBoardItemToBoardRequest;
use App\Models\BoardActivityLog;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardActivityLogger;
use App\Services\Board\BoardItemTransferService;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The item drawer's "Move to" > "Move to board" action: listing the boards an
 * item can be moved into, and performing the move itself. Moving within the
 * same board ("Move to group") reuses `BoardItemController::bulkMove()`.
 */
class BoardItemMoveController extends Controller
{
    public function __construct(
        private readonly BoardItemTransferService $transfer_service,
        private readonly BoardActivityLogger $activity_logger,
    ) {}

    /**
     * GET /api/boards/{item}/move-targets
     *
     * Every other board in the same workspace the user may edit that has at
     * least one table in its primary tab, each with those tables, enough for
     * the drawer's board + table picker in a single request.
     */
    public function targets(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $boards = WorkspaceNavigationItem::boards()
            ->notArchived()
            ->where('workspace_id', $item->workspace_id)
            ->where('id', '!=', $item->id)
            ->with(['groups' => fn ($query) => $query
                ->whereHas('boardView', fn ($view_query) => $view_query->where('is_primary', true))
                ->orderBy('position')])
            ->orderBy('label')
            ->get()
            ->filter(fn (WorkspaceNavigationItem $board) => $board->groups->isNotEmpty()
                && BoardEditGate::allows($board, $request->user()))
            ->values();

        return response()->json([
            'data' => $boards->map(fn (WorkspaceNavigationItem $board) => [
                'id' => $board->id,
                'label' => $board->label,
                'groups' => $board->groups->map(fn (BoardGroup $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'accent_color' => $group->accent_color,
                ])->values(),
            ]),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/items/{board_item}/board
     *
     * Moves a top-level item (and its subitems) into a table of another board
     * of the same workspace. See {@see BoardItemTransferService} for what
     * carries across.
     */
    public function store(MoveBoardItemToBoardRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        abort_if($board_item->board_id !== $item->id, 404);
        abort_if($board_item->parent_id !== null, 422, 'Only top-level items can be moved to another board.');
        BoardEditGate::authorizeItem($item, $request->user(), $board_item);

        $validated = $request->validated();
        abort_if((int) $validated['target_board_id'] === $item->id, 422, 'Use "Move to group" to move an item within the same board.');

        $target_board = WorkspaceNavigationItem::boards()
            ->notArchived()
            ->where('workspace_id', $item->workspace_id)
            ->findOrFail($validated['target_board_id']);
        BoardEditGate::authorizeContent($target_board, $request->user());

        $target_group = $target_board->groups()->findOrFail($validated['target_group_id']);

        $moved_item = $this->transfer_service->moveToBoard($board_item, $target_group);

        $meta = ['item_id' => $moved_item->id, 'from_board_id' => $item->id, 'to_board_id' => $target_board->id];
        $this->activity_logger->log(
            $item,
            $request->user(),
            BoardActivityLog::ACTION_ITEM_MOVED,
            sprintf('Moved "%s" to the board "%s"', $moved_item->name, $target_board->label),
            $meta,
        );
        $this->activity_logger->log(
            $target_board,
            $request->user(),
            BoardActivityLog::ACTION_ITEM_MOVED,
            sprintf('Moved "%s" here from the board "%s"', $moved_item->name, $item->label),
            $meta,
        );

        return response()->json([
            'message' => 'Item moved to the board successfully.',
            'target_board' => ['id' => $target_board->id, 'label' => $target_board->label],
        ]);
    }
}
