<?php

namespace App\Mail;

use App\Models\BoardInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by {@link \App\Http\Controllers\Board\BoardInvitationController::store()}
 * via {@link \App\Jobs\SendEmailJob}, one per invited email address. Mirrors
 * {@link WorkspaceInvitationMail}, scoped to a single board rather than a
 * whole workspace.
 */
class BoardInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public BoardInvitation $invitation)
    {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->invitation->inviter->full_name} invited you to view {$this->invitation->board->label}",
        );
    }

    public function content(): Content
    {
        $frontend_url = rtrim(config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.board-invitation',
            with: [
                'board_label' => $this->invitation->board->label,
                'workspace_name' => $this->invitation->board->workspace->name,
                'workspace_mono' => $this->invitation->board->workspace->mono,
                'workspace_color' => $this->invitation->board->workspace->color,
                'inviter_name' => $this->invitation->inviter->full_name,
                'invite_message' => $this->invitation->message,
                'accept_url' => "{$frontend_url}/board-invitations/{$this->invitation->code}",
                'expires_at' => $this->invitation->expires_at,
            ],
        );
    }
}
