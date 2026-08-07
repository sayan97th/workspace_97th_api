<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace member, read off {@link \App\Models\Workspace::users()}'s pivot
 * (`workspace_user.role`/`is_recent`) — powers Manage Workspace's
 * "Collaborations" tab.
 *
 * @mixin User
 */
class WorkspaceMemberResource extends JsonResource
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
            'role' => $this->pivot->role,
            'is_recent' => (bool) $this->pivot->is_recent,
            'joined_at' => $this->pivot->created_at,
        ];
    }
}
