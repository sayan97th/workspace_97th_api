<?php

namespace App\Actions\Workspaces;

use App\Http\Controllers\Auth\WorkspaceInvitationController;
use App\Models\User;
use App\Models\WorkspaceInvitation;

class JoinPendingWorkspaceInvitations
{
    /**
     * Auto-joins a freshly registered user to every workspace they were
     * invited to before they had an account, so people who sign up on their
     * own (rather than through the emailed invitation link) still land in
     * the workspace without needing to be re-invited. Mirrors the attach
     * step in {@see WorkspaceInvitationController::accept()}.
     */
    public function handle(User $user): void
    {
        $pending_invitations = WorkspaceInvitation::where('email', $user->email)
            ->whereNull('accepted_at')
            ->get()
            ->filter(fn (WorkspaceInvitation $invitation) => $invitation->isPending());

        foreach ($pending_invitations as $invitation) {
            $invitation->workspace->users()->syncWithoutDetaching([
                $user->id => ['role' => $invitation->role, 'is_recent' => true, 'invited_by' => $invitation->invited_by],
            ]);

            $invitation->update(['accepted_at' => now()]);
        }
    }
}
