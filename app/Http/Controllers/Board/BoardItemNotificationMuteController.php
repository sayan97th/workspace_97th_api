<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Models\BoardItem;
use App\Models\BoardItemNotificationMute;
use App\Models\WorkspaceNavigationItem;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user, per-item notification muting, checked by
 * {@see NotificationService::notify()} next to the
 * board level {@see BoardNotificationMuteController}. It silences everything
 * about one item and its comment thread while the rest of the board stays loud.
 */
class BoardItemNotificationMuteController extends Controller
{
    /**
     * GET /api/boards/muted-items
     *
     * Every item the current user has muted, for the notification preferences
     * panel's list with an "Unmute" button.
     */
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->boardItemNotificationMutes()
            ->with('boardItem.board:id,label')
            ->latest('id')
            ->get()
            ->filter(fn (BoardItemNotificationMute $mute) => $mute->boardItem !== null)
            ->map(fn (BoardItemNotificationMute $mute) => [
                'board_id' => $mute->boardItem->board_id,
                'board_item_id' => $mute->board_item_id,
                'item_name' => $mute->boardItem->name,
                'board_name' => $mute->boardItem->board?->label ?? __('Deleted board'),
            ])
            ->values();

        return response()->json(['data' => $items]);
    }

    /**
     * POST /api/boards/{item}/items/{board_item}/mute
     */
    public function store(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->authorizeItem($request, $item, $board_item);

        BoardItemNotificationMute::firstOrCreate([
            'user_id' => $request->user()->id,
            'board_item_id' => $board_item->id,
        ]);

        return response()->json(['message' => 'Item muted successfully.']);
    }

    /**
     * DELETE /api/boards/{item}/items/{board_item}/mute
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->authorizeItem($request, $item, $board_item);

        BoardItemNotificationMute::where('user_id', $request->user()->id)->where('board_item_id', $board_item->id)->delete();

        return response()->json(['message' => 'Item unmuted successfully.']);
    }

    /**
     * The item has to sit on the board in the URL, and the caller has to belong
     * to that board's workspace, so nobody can probe items they cannot reach.
     */
    private function authorizeItem(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item): void
    {
        abort_if($board_item->board_id !== $item->id, 404);
        abort_unless($request->user()->workspaces()->where('workspaces.id', $item->workspace_id)->exists(), 403);
    }
}
