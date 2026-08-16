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
                'unread_count' => $request->user()->notifications()->unread()->count(),
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
}
