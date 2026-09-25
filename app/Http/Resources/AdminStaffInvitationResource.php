<?php

namespace App\Http\Resources;

use App\Models\StaffInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Administration > Users > Invitations row. Extends {@see StaffInvitationResource} with who
 * sent it, the department and a computed `status` (pending, expired or accepted).
 *
 * @mixin StaffInvitation
 */
class AdminStaffInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'message' => $this->message,
            'status' => $this->isAccepted() ? 'accepted' : ($this->isExpired() ? 'expired' : 'pending'),
            'expires_at' => $this->expires_at,
            'accepted_at' => $this->accepted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'inviter' => $this->whenLoaded('inviter', fn () => $this->inviter ? [
                'id' => $this->inviter->id,
                'full_name' => $this->inviter->full_name,
                'profile_photo_url' => $this->inviter->profile_photo_url,
            ] : null),
        ];
    }
}
