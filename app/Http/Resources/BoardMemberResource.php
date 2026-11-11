<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\BoardMembers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person added to a board ({@see BoardMembers}), one row of the roster its People pickers,
 * `@mention` pickers and Notify pickers offer.
 *
 * @mixin User
 */
class BoardMemberResource extends JsonResource
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
            // Shown next to the name in the pickers, "Name (Job title)".
            'job_title' => $this->job_title,
        ];
    }
}
