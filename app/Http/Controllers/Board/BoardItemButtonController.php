<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardAutomationService;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A Button column's cell: pressing it stores nothing, it only fires the "When button is clicked"
 * automations watching that column on the item.
 */
class BoardItemButtonController extends Controller
{
    /**
     * POST /api/boards/{item}/items/{board_item}/buttons/{column}
     */
    public function press(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardColumn $column, BoardAutomationService $automation_service): JsonResponse
    {
        abort_if($board_item->board_id !== $item->id || $column->board_id !== $item->id, 404);
        abort_if($column->type !== BoardColumn::TYPE_BUTTON, 422, 'This column is not a button.');
        abort_if($board_item->is_archived, 422, 'Restore the item before pressing its button.');
        BoardEditGate::authorizeItem($item, $request->user(), $board_item);

        $board_item->loadMissing('group');
        abort_if($board_item->group?->board_view_id !== $column->board_view_id, 404);
        $expected_scope = $board_item->parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;
        abort_if($column->scope !== $expected_scope, 422, 'This button belongs to another kind of row.');

        $ran = $automation_service->handleButtonClicked($board_item, $column, $request->user());

        return response()->json([
            'message' => $ran === 0 ? 'No automation listens to this button yet.' : ($ran === 1 ? 'Ran 1 automation.' : "Ran {$ran} automations."),
            'automations_run' => $ran,
            'is_board_paused' => $automation_service->isBoardPaused($item->id),
        ]);
    }
}
