<?php

namespace App\Mail;

use App\Models\WorkspaceInvitation;
use App\Support\WorkspacePermissionCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by {@link \App\Http\Controllers\Workspace\WorkspaceInvitationController::store()}
 * via {@link \App\Jobs\SendEmailJob}, one per invited email address.
 */
class WorkspaceInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public WorkspaceInvitation $invitation)
    {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join {$this->invitation->workspace->name}",
        );
    }

    public function content(): Content
    {
        $frontend_url = rtrim(config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.workspace-invitation',
            with: [
                'workspace_name' => $this->invitation->workspace->name,
                'inviter_name' => $this->invitation->inviter->full_name,
                'role_label' => WorkspacePermissionCatalog::labelFor($this->invitation->role),
                'invite_message' => $this->invitation->message,
                'accept_url' => "{$frontend_url}/invitations/{$this->invitation->code}",
                'expires_at' => $this->invitation->expires_at,
            ],
        );
    }
}
