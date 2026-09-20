<?php

namespace App\Http\Resources;

use App\Models\AccountTeam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row in the Teams rail. The controller must eager-load `members_count` (via
 * `withCount('members')` / `loadCount('members')`) and the `owners` relation before returning
 * this, so the per-row permission flags below never trigger an N+1.
 *
 * @mixin AccountTeam
 */
class AccountTeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $is_admin = $viewer !== null && $viewer->hasRole(['super_admin', 'admin']);
        $is_team_owner = $viewer !== null && $this->owners->contains('id', $viewer->id);

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'member_count' => (int) $this->members_count,
            'owners' => $this->owners->map(fn ($owner) => [
                'id' => (string) $owner->id,
                'full_name' => $owner->full_name,
                'email' => $owner->email,
                'profile_photo_url' => $owner->profile_photo_url,
            ])->values(),
            // Admins and the account owner manage the team itself and who owns it, a team owner
            // only manages the roster.
            'can_manage' => $is_admin,
            'can_manage_members' => $is_admin || $is_team_owner,
            'created_at' => $this->created_at,
        ];
    }
}
