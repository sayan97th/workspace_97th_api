<?php

namespace App\Http\Resources;

use App\Models\BoardInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BoardInvitation
 */
class BoardInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'status' => $this->isAccepted() ? 'accepted' : ($this->isExpired() ? 'expired' : 'pending'),
            'message' => $this->message,
            'inviter' => $this->whenLoaded('inviter', fn () => [
                'id' => $this->inviter->id,
                'full_name' => $this->inviter->full_name,
                'profile_photo_url' => $this->inviter->profile_photo_url,
            ]),
            'expires_at' => $this->expires_at,
            'accepted_at' => $this->accepted_at,
            'created_at' => $this->created_at,
        ];
    }
}
