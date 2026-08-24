<?php

namespace App\Mail;

use App\Models\StaffInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by {@link \App\Http\Controllers\Admin\User\UserController::invite()} via
 * {@link \App\Jobs\SendEmailJob}.
 */
class StaffInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public StaffInvitation $invitation)
    {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->invitation->inviter->full_name} invited you to join 97th Floor",
        );
    }

    public function content(): Content
    {
        $frontend_url = rtrim((string) config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.staff-invitation',
            with: [
                'inviter_name' => $this->invitation->inviter->full_name,
                'role_label' => ucwords(str_replace('_', ' ', $this->invitation->role)),
                'invite_message' => $this->invitation->message,
                'accept_url' => "{$frontend_url}/staff-invitations/{$this->invitation->code}",
                'expires_at' => $this->invitation->expires_at,
            ],
        );
    }
}
