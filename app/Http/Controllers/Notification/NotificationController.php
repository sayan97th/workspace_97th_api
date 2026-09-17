<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     *
     * The current user's most recent notifications, newest first. Powers the
     * bell drawer's list — filtering by tab/search/unread is done client-side
     * over this set, mirroring how the rest of the notification drawer works.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()
            ->whereNull('dismissed_at')
            ->with(['actor', 'board'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => NotificationResource::collection($notifications),
        ]);
    }

    /**
     * GET /api/notifications/unread-count
     *
     * Powers the bell icon's unread dot.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'unread_count' => $request->user()->notifications()->unread()->whereNull('dismissed_at')->count(),
            ],
        ]);
    }

    /**
     * PATCH /api/notifications/{notification}/read
     */
    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 403);

        $notification->markAsRead();

        return response()->json([
            'data' => [
                'id' => (string) $notification->id,
                'is_unread' => false,
            ],
        ]);
    }

    /**
     * PATCH /api/notifications/read-all
     *
     * Marks every one of the current user's unread, non-dismissed
     * notifications as read — the bell drawer's "Mark all as read".
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->notifications()
            ->unread()
            ->whereNull('dismissed_at')
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    /**
     * DELETE /api/notifications/{notification}
     *
     * Soft-dismisses a single notification (the bell drawer's per-item "×")
     * — it stops showing up in {@see index()}/{@see unreadCount()} without
     * being hard-deleted.
     */
    public function dismiss(Request $request, Notification $notification): JsonResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 403);

        $notification->update(['dismissed_at' => now()]);

        return response()->json(['message' => 'Notification dismissed.']);
    }
}
