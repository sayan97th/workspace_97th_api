<?php

namespace App\Http\Resources;

use App\Models\AccountTeam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row in the Teams rail. Load it through {@see AccountTeam::loadResourceData()} or the
 * `withResourceData` scope first, they add the counts, the `owners` relation and the viewer's
 * `is_member` flag read below, so a list never triggers an N+1.
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
            'parent_id' => $this->parent_id !== null ? (string) $this->parent_id : null,
            'allow_guests' => (bool) $this->allow_guests,
            'picture_url' => $this->picture_url,
            'cover_url' => $this->cover_url,
            'member_count' => (int) $this->members_count,
            'sub_team_count' => (int) ($this->sub_teams_count ?? 0),
            'board_count' => (int) ($this->boards_count ?? 0),
            'owners' => $this->owners->map(fn ($owner) => [
                'id' => (string) $owner->id,
                'full_name' => $owner->full_name,
                'email' => $owner->email,
                'profile_photo_url' => $owner->profile_photo_url,
            ])->values(),
            'is_member' => (bool) ($this->is_member ?? false),
            'is_viewer_owner' => $is_team_owner,
            // Admins and the account owner manage the team itself and who owns it, a team owner
            // manages the roster, the picture, the cover and the boards the team joined.
            'can_manage' => $is_admin,
            'can_manage_members' => $is_admin || $is_team_owner,
            'created_at' => $this->created_at,
        ];
    }
}
