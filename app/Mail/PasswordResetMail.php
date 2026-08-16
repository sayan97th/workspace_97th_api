<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by {@link User::sendPasswordResetNotification()} via
 * {@link \App\Jobs\SendEmailJob}, in place of the framework's stock
 * `Illuminate\Auth\Notifications\ResetPassword` notification, so the reset
 * email is queued through the same throttled `emails` pipeline as every
 * other transactional email in the app.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $token,
        public string $resetUrl,
        public int $expiresMinutes,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset',
            with: [
                'first_name' => trim(explode(' ', $this->user->full_name)[0] ?? $this->user->full_name),
                'reset_url' => $this->resetUrl,
                'expires_minutes' => $this->expiresMinutes,
            ],
        );
    }
}
