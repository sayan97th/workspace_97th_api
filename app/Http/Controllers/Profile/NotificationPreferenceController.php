<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateNotificationPreferencesRequest;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;

class NotificationPreferenceController extends Controller
{
    /**
     * PATCH /api/profile/notifications
     */
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $updates = [];

        if (array_key_exists('preferences', $validated)) {
            $updates['notification_preferences'] = array_merge(
                $user->notification_preferences ?? [],
                $validated['preferences'],
            );
        }

        if (array_key_exists('desktop_notifications_enabled', $validated)) {
            $updates['desktop_notifications_enabled'] = $validated['desktop_notifications_enabled'];
        }

        $user->update($updates);

        return response()->json([
            'message' => 'Notification preferences updated successfully.',
            'user' => new ProfileResource($user->fresh()),
        ]);
    }
}
