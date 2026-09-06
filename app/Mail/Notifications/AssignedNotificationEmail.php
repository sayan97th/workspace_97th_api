<?php

namespace App\Mail\Notifications;

use App\Http\Controllers\Board\BoardItemController;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Dedicated email for {@see Notification::TYPE_ASSIGNED}, dispatched by
 * {@see NotificationService::notify()} in place of
 * the generic {@see NotificationEmail} whenever the notification carries a
 * `boardItem` (see {@see BoardItemController::notifyNewlyAssignedPeople()}).
 * Unlike the generic activity email, this surfaces the full item/table/view/
 * workspace breadcrumb the recipient needs to place the row they were just
 * put on, plus a direct "Open item" button.
 */
class AssignedNotificationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Notification $notification)
    {
        //
    }

    public function envelope(): Envelope
    {
        $actor_name = $this->notification->actor?->full_name ?? 'Someone';
        $item_name = $this->notification->boardItem?->name ?? 'an item';

        return new Envelope(
            subject: "{$actor_name} assigned you to \"{$item_name}\"",
        );
    }

    public function content(): Content
    {
        $frontend_url = rtrim((string) config('app.frontend_url'), '/');
        $actor = $this->notification->actor;
        $board_item = $this->notification->boardItem;
        $group = $board_item?->group;
        $board_view = $group?->boardView;
        $board = $this->notification->board;
        $workspace = $board?->workspace;

        return new Content(
            view: 'emails.notifications.assigned',
            with: [
                'actor_name' => $actor?->full_name ?? 'Someone',
                'actor_initials' => $this->initialsFor($actor),
                'actor_photo_url' => $actor?->profile_photo_url,
                'item_name' => $board_item?->name ?? 'this item',
                'table_name' => $group?->name,
                'view_name' => $board_view?->label,
                'board_label' => $board?->label,
                'workspace_name' => $workspace?->name,
                'workspace_mono' => $workspace?->mono,
                'workspace_color' => $workspace?->color,
                'cta_url' => $this->notification->link ? $frontend_url.$this->notification->link : $frontend_url,
            ],
        );
    }

    /**
     * Up to two uppercase initials derived from `$actor`'s name, used as the
     * avatar fallback when they have no `profile_photo_url`. Mirrors
     * {@see NotificationEmail::initialsFor()} and the frontend's
     * `getUserInitials()` (`src/lib/user.ts`).
     */
    private function initialsFor(?User $actor): string
    {
        if ($actor === null) {
            return '?';
        }

        $source = trim("{$actor->first_name} {$actor->last_name}") ?: $actor->full_name;
        $words = preg_split('/[\s._-]+/', trim($source), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($words)) {
            return '?';
        }

        if (count($words) === 1) {
            return strtoupper(mb_substr($words[0], 0, 2));
        }

        return strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[count($words) - 1], 0, 1));
    }
}
