<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff user shown in a Teams roster (a single team's members, the account-wide
 * "All members" dedupe, or the "add members" candidate directory). The controller
 * must eager-load `roles:id,name` before returning this, since `is_owner` reads it.
 *
 * @mixin User
 */
class AccountTeamMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'job_title' => $this->job_title,
            'profile_photo_url' => $this->profile_photo_url,
            'is_owner' => $this->hasRole('super_admin'),
        ];
    }
}
