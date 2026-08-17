<?php

namespace App\Services\Notification;

use App\Events\NewNotification;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;

/**
 * Single entry point for creating and delivering in-app notifications. Every
 * trigger (a mention, a reply, a reaction, an assignment) funnels through
 * {@see self::notify()}, so a new trigger point is always a one-line addition
 * rather than duplicated "create row + broadcast" logic.
 */
class NotificationService
{
    /**
     * Creates a notification for `$recipient` and broadcasts it over the
     * `notifications.{user_id}` private channel, unless `$actor` is
     * notifying themselves or `$recipient` has this `$type`'s in-app channel
     * turned off in their `notification_preferences`.
     *
     * `$actor` is nullable to support system-generated notifications (e.g.
     * the websocket test broadcast) that aren't triggered by another user.
     */
    public function notify(
        User $recipient,
        ?User $actor,
        string $type,
        ?WorkspaceNavigationItem $board,
        string $action_label,
        string $action_target,
        ?string $link,
    ): ?Notification {
        if ($actor !== null && $recipient->id === $actor->id) {
            return null;
        }

        $preferences = $recipient->notification_preferences ?? [];
        if (($preferences["{$type}_app"] ?? true) === false) {
            return null;
        }

        $notification = Notification::create([
            'user_id' => $recipient->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'board_id' => $board?->id,
            'action_label' => $action_label,
            'action_target' => $action_target,
            'link' => $link,
        ]);

        broadcast(new NewNotification($notification->load(['actor', 'board'])));

        return $notification;
    }
}
