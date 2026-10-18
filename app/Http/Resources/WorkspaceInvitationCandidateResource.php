<?php

namespace App\Http\Resources;

use App\Http\Controllers\Workspace\WorkspaceInvitationController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A platform user offered up as a pick from the "pool of users" autocomplete
 * on the invite-member form, {@see WorkspaceInvitationController::availableUsers()}.
 * Deliberately as small as {@link WorkspaceMemberResource}, minus the
 * workspace-membership pivot fields this user doesn't have yet.
 *
 * @mixin User
 */
class WorkspaceInvitationCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'profile_photo_url' => $this->profile_photo_url,
            'job_title' => $this->job_title,
            // Set by the controller when this person already has an open invitation to the workspace.
            'has_pending_invitation' => (bool) $this->getAttribute('has_pending_invitation'),
        ];
    }
}
