<?php

namespace App\Http\Resources;

use App\Models\StaffInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffInvitation
 */
class StaffInvitationResource extends JsonResource
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
            'department_id' => $this->department_id,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
