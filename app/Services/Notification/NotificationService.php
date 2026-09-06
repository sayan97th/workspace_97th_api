<?php

namespace App\Services\Notification;

use App\Events\NewNotification;
use App\Jobs\SendEmailJob;
use App\Mail\Notifications\AssignedNotificationEmail;
use App\Mail\Notifications\NotificationEmail;
use App\Models\BoardItem;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;

/**
 * Single entry point for creating and delivering notifications, both in-app
 * and by email. Every trigger (a mention, a reply, a reaction, an assignment)
 * funnels through {@see self::notify()}, so a new trigger point is always a
 * one-line addition rather than duplicated "create row + broadcast + email"
 * logic.
 */
class NotificationService
{
    /**
     * Creates a notification for `$recipient`, broadcasts it over the
     * `notifications.{user_id}` private channel, and queues an email for it,
     * each gated by `$recipient`'s own `notification_preferences` for this
     * `$type` (the `_app` and `_email` channel keys). No-ops entirely when
     * `$actor` is notifying themselves.
     *
     * `$actor` is nullable to support system-generated notifications (e.g.
     * the websocket test broadcast); those never send email, since there's
     * no one to attribute the email to and they're diagnostic, not activity.
     *
     * `$board_item` links the notification back to the exact row that
     * triggered it (currently only passed for {@see Notification::TYPE_ASSIGNED}),
     * so its email can render the item/table/view/workspace breadcrumb — see
     * {@see AssignedNotificationEmail}.
     */
    public function notify(
        User $recipient,
        ?User $actor,
        string $type,
        ?WorkspaceNavigationItem $board,
        string $action_label,
        string $action_target,
        ?string $link,
        ?BoardItem $board_item = null,
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
            'board_item_id' => $board_item?->id,
            'action_label' => $action_label,
            'action_target' => $action_target,
            'link' => $link,
        ]);

        $notification->load(['actor', 'board']);

        broadcast(new NewNotification($notification));

        if ($actor !== null && ($preferences["{$type}_email"] ?? true) !== false) {
            $notification->load(['boardItem.group.boardView', 'boardItem.board.workspace']);

            $mailable = $type === Notification::TYPE_ASSIGNED && $notification->boardItem !== null
                ? new AssignedNotificationEmail($notification)
                : new NotificationEmail($notification);

            SendEmailJob::dispatch($mailable, $recipient->email);
        }

        return $notification;
    }
}
