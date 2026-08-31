<?php

namespace App\Mail\Notifications;

use App\Models\Notification;
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

        return new Content(
            view: 'emails.notifications.activity',
            with: [
                'actor_name' => $this->notification->actor?->full_name ?? 'Someone',
                'action_label' => $this->notification->action_label,
                'action_target' => $this->notification->action_target,
                'board_label' => $this->notification->board?->label,
                'cta_url' => $this->notification->link ? $frontend_url.$this->notification->link : $frontend_url,
            ],
        );
    }
}
