<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\UpdateConnectBoardSettingsRequest;
use App\Http\Resources\BoardColumnResource;
use App\Models\BoardColumn;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\ConnectBoardColumnService;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Connect boards column, see {@see ConnectBoardColumnService}: the data its cell picker
 * lists and the settings its "Boards settings" dialog saves.
 */
class BoardColumnConnectController extends Controller
{
    public function __construct(private readonly ConnectBoardColumnService $connect_service) {}

    /**
     * GET /api/boards/{item}/columns/{column}/connect-items
     *
     * The connected boards with their groups, columns and items, for both the cell's linked
     * item names and its "Choose items" picker. Boards the user may not open are left out
     * and only counted.
     */
    public function items(Request $request, WorkspaceNavigationItem $item, BoardColumn $column): JsonResponse
    {
        $this->ensureConnectColumn($item, $column);

        return response()->json($this->connect_service->options($column, $request->user()));
    }

    /**
     * GET /api/boards/{item}/columns/{column}/connect-settings
     *
     * The column's settings with its connected boards, for "Connected board column settings".
     */
    public function settings(Request $request, WorkspaceNavigationItem $item, BoardColumn $column): JsonResponse
    {
        $this->ensureConnectColumn($item, $column);

        return response()->json([
            'boards' => $this->connect_service->connectedBoards($column, $request->user()),
            'allow_multiple_items' => (bool) ($column->config['allow_multiple_items'] ?? true),
            'two_way' => (bool) ($column->config['two_way'] ?? false),
        ]);
    }

    /**
     * PUT /api/boards/{item}/columns/{column}/connect-settings
     */
    public function updateSettings(UpdateConnectBoardSettingsRequest $request, WorkspaceNavigationItem $item, BoardColumn $column): JsonResponse
    {
        $this->ensureConnectColumn($item, $column);
        BoardEditGate::authorizeStructure($item, $request->user());

        $updated = $this->connect_service->updateSettings($item, $column, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Connected boards updated successfully.',
            'column' => new BoardColumnResource($updated),
        ]);
    }

    private function ensureConnectColumn(WorkspaceNavigationItem $item, BoardColumn $column): void
    {
        abort_unless($column->board_id === $item->id && $column->type === BoardColumn::TYPE_CONNECT_BOARD, 404);
    }
}
