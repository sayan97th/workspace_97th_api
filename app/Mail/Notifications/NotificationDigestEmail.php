<?php

namespace App\Mail\Notifications;

use App\Enums\EmailDigestFrequency;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The daily or weekly summary of a user's unread notifications, queued by
 * {@link \App\Services\Notification\NotificationDigestService} through
 * {@link \App\Jobs\SendEmailJob}.
 */
class NotificationDigestEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, Notification>  $notifications  The newest unread ones, already capped for display.
     */
    public function __construct(
        public User $recipient,
        public Collection $notifications,
        public int $total_unread,
        public EmailDigestFrequency $frequency,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        $period = $this->frequency === EmailDigestFrequency::Weekly ? 'weekly' : 'daily';
        $noun = $this->total_unread === 1 ? 'notification' : 'notifications';

        return new Envelope(subject: "Your {$period} digest: {$this->total_unread} unread {$noun}");
    }

    public function content(): Content
    {
        $frontend_url = rtrim((string) config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.notifications.digest',
            with: [
                'recipient_name' => $this->recipient->first_name ?: $this->recipient->full_name,
                'period' => $this->frequency === EmailDigestFrequency::Weekly ? 'week' : 'day',
                'total_unread' => $this->total_unread,
                'hidden_count' => max(0, $this->total_unread - $this->notifications->count()),
                'items' => $this->notifications->map(fn (Notification $notification) => [
                    'actor_name' => $notification->actor?->full_name ?? 'Someone',
                    'action_label' => $notification->action_label,
                    'action_target' => $notification->action_target,
                    'board_label' => $notification->board?->label,
                    'url' => $notification->link ? $frontend_url.$notification->link : $frontend_url,
                    'time_label' => $notification->created_at?->diffForHumans(),
                ])->all(),
                'cta_url' => $frontend_url,
                'preferences_url' => $frontend_url.'/profile?section=notifications',
            ],
        );
    }
}
