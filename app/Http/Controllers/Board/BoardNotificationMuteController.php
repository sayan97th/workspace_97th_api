<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Models\BoardNotificationMute;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user, per-board notification muting — checked by
 * {@see \App\Services\Notification\NotificationService::notify()} ahead of
 * the recipient's own per-type preferences (Profile > Notifications).
 */
class BoardNotificationMuteController extends Controller
{
    /**
     * GET /api/boards/muted
     *
     * Every board id the current user has muted — powers the bell drawer's
     * notification preferences panel.
     */
    public function index(Request $request): JsonResponse
    {
        $board_ids = $request->user()->boardNotificationMutes()
            ->with('board')
            ->get()
            ->map(fn (BoardNotificationMute $mute) => [
                'board_id' => $mute->board_id,
                'board_name' => $mute->board?->label ?? __('Deleted board'),
            ])
            ->values();

        return response()->json(['data' => $board_ids]);
    }

    /**
     * POST /api/boards/{item}/mute
     */
    public function store(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        BoardNotificationMute::firstOrCreate([
            'user_id' => $request->user()->id,
            'board_id' => $item->id,
        ]);

        return response()->json(['message' => 'Board muted successfully.']);
    }

    /**
     * DELETE /api/boards/{item}/mute
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        BoardNotificationMute::where('user_id', $request->user()->id)->where('board_id', $item->id)->delete();

        return response()->json(['message' => 'Board unmuted successfully.']);
    }
}
