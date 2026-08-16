<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once per new account, right after registration completes, via
 * {@link \App\Jobs\SendEmailJob} from every place a {@see User} is created
 * (standard sign up, Fortify's own registration action, and Google sign in).
 */
class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user)
    {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to '.config('app.name').'!',
        );
    }

    public function content(): Content
    {
        $frontend_url = rtrim((string) config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.welcome',
            with: [
                'first_name' => trim(explode(' ', $this->user->full_name)[0] ?? $this->user->full_name),
                'login_url' => $frontend_url,
            ],
        );
    }
}
