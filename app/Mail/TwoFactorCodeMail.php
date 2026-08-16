<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by {@link \App\Http\Controllers\Auth\AuthController::login()} via
 * {@link \App\Jobs\SendEmailJob} whenever a two factor enabled user signs
 * in, giving them an email delivered code as an alternative to their
 * authenticator app.
 */
class TwoFactorCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
        public int $expiresMinutes,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your verification code is {$this->code}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.two-factor-code',
            with: [
                'first_name' => trim(explode(' ', $this->user->full_name)[0] ?? $this->user->full_name),
                'code' => $this->code,
                'expires_minutes' => $this->expiresMinutes,
            ],
        );
    }
}
