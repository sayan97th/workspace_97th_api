<?php

namespace App\Mail\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email counterpart of an in-app {@see Notification}, dispatched exclusively
 * by {@link \App\Services\Notification\NotificationService::notify()} via
 * {@link \App\Jobs\SendEmailJob}. Works for every notification type without
 * a per-type subject/copy table, since `$notification->action_label` and
 * `$notification->action_target` already read as a natural sentence.
 */
class NotificationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Notification $notification)
    {
        //
    }

    public function envelope(): Envelope
    {
        $actor_name = $this->notification->actor?->full_name ?? 'Someone';

        return new Envelope(
            subject: "{$actor_name} ".lcfirst($this->notification->action_label),
        );
    }

    public function content(): Content
    {
        $frontend_url = rtrim((string) config('app.frontend_url'), '/');
        $actor = $this->notification->actor;

        return new Content(
            view: 'emails.notifications.activity',
            with: [
                'actor_name' => $actor?->full_name ?? 'Someone',
                'actor_initials' => $this->initialsFor($actor),
                'actor_photo_url' => $actor?->profile_photo_url,
                'action_label' => $this->notification->action_label,
                'action_target' => $this->notification->action_target,
                'board_label' => $this->notification->board?->label,
                'cta_url' => $this->notification->link ? $frontend_url.$this->notification->link : $frontend_url,
            ],
        );
    }

    /**
     * Up to two uppercase initials derived from `$actor`'s name, used as the
     * avatar fallback when they have no `profile_photo_url`. Mirrors the
     * frontend's `getUserInitials()` (`src/lib/user.ts`) so the initials
     * shown in the email match the ones shown in the app.
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
