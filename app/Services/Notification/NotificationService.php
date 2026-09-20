<?php

namespace App\Services\Notification;

use App\Events\NewNotification;
use App\Jobs\SendEmailJob;
use App\Mail\Notifications\AssignedNotificationEmail;
use App\Mail\Notifications\NotificationEmail;
use App\Models\BoardComment;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardItemNotificationMute;
use App\Models\BoardNotificationMute;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Slack\SlackNotifier;

/**
 * Single entry point for creating and delivering notifications, both in-app
 * and by email. Every trigger (a mention, a reply, a reaction, an assignment)
 * funnels through {@see self::notify()}, so a new trigger point is always a
 * one-line addition rather than duplicated "create row + broadcast + email"
 * logic.
 */
class NotificationService
{
    public function __construct(private readonly SlackNotifier $slack_notifier) {}

    /**
     * Creates a notification for `$recipient`, broadcasts it over the
     * `notifications.{user_id}` private channel, and queues an email for it,
     * each gated by `$recipient`'s own `notification_preferences` for this
     * `$type` (the `_app` and `_email` channel keys). No-ops entirely when
     * `$actor` is notifying themselves, or when `$recipient` has muted `$board`
     * (see {@see BoardNotificationMute}) or the item the notification is about
     * (see {@see BoardItemNotificationMute}), checked ahead of the per-type gate,
     * since muting is meant to silence every notification type for it.
     *
     * While the recipient's quiet hours are active ({@see User::isInQuietHours()})
     * only the in-app notification is created, no email or Slack message goes out.
     *
     * A Slack direct message is sent as a third channel, gated by the recipient's
     * `<type>_slack` preference and by whether they linked their Slack account, see
     * {@see SlackNotifier::deliverNotification()}.
     *
     * `$actor` is nullable to support system-generated notifications (e.g.
     * the websocket test broadcast); those never send email, since there's
     * no one to attribute the email to and they're diagnostic, not activity.
     *
     * `$board_item` links the notification back to the exact row that
     * triggered it (currently only passed for {@see Notification::TYPE_ASSIGNED}),
     * so its email can render the item/table/view/workspace breadcrumb — see
     * {@see AssignedNotificationEmail}.
     *
     * `$comment` is the comment that triggered it, when there is one. The
     * notification keeps a reference to it so the bell can reply to that thread
     * inline, and an item comment also ties the notification to its item, which
     * is what muting an item silences.
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
        BoardItemComment|BoardComment|null $comment = null,
    ): ?Notification {
        if ($actor !== null && $recipient->id === $actor->id) {
            return null;
        }

        if ($board !== null && BoardNotificationMute::where('user_id', $recipient->id)->where('board_id', $board->id)->exists()) {
            return null;
        }

        $board_item ??= $comment instanceof BoardItemComment ? $comment->item : null;

        if ($board_item !== null && BoardItemNotificationMute::where('user_id', $recipient->id)->where('board_item_id', $board_item->id)->exists()) {
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
            'comment_id' => $comment?->id,
            'comment_kind' => match (true) {
                $comment instanceof BoardItemComment => 'item',
                $comment instanceof BoardComment => 'board',
                default => null,
            },
            'action_label' => $action_label,
            'action_target' => $action_target,
            'link' => $link,
        ]);

        $notification->load(['actor', 'board']);

        broadcast(new NewNotification($notification));

        // Do Not Disturb keeps the notification in the bell, but stops every
        // channel that would interrupt the recipient (email, Slack, and the
        // toast/desktop push the websocket payload's `is_silenced` flag gates).
        if ($recipient->isInQuietHours()) {
            return $notification;
        }

        if ($actor !== null && ($preferences["{$type}_email"] ?? true) !== false) {
            $notification->load(['boardItem.group.boardView', 'boardItem.board.workspace']);

            $mailable = $type === Notification::TYPE_ASSIGNED && $notification->boardItem !== null
                ? new AssignedNotificationEmail($notification)
                : new NotificationEmail($notification);

            SendEmailJob::dispatch($mailable, $recipient->email);
        }

        $this->slack_notifier->deliverNotification($notification, $recipient);

        return $notification;
    }

    /**
     * Brings back every snoozed notification whose time has come: clears the
     * snooze, flags it unread again and broadcasts it like a fresh one, so an
     * open bell shows it right away. Run every minute by
     * `notifications:wake-snoozed` (see routes/console.php). It keeps its
     * original `created_at`, so after a reload it sits where it always did in
     * the list, only the live push and the unread dot resurface it.
     */
    public function wakeSnoozed(): int
    {
        $due = Notification::query()
            ->whereNull('dismissed_at')
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->with(['actor', 'board', 'user'])
            ->get();

        foreach ($due as $notification) {
            $notification->update(['snoozed_until' => null, 'is_read' => false, 'read_at' => null]);

            broadcast(new NewNotification($notification));
        }

        return $due->count();
    }
}
