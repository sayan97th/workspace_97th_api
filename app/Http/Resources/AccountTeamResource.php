<?php

namespace App\Http\Resources;

use App\Models\AccountTeam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row in the Teams rail — the controller must eager-load `members_count`
 * (via `withCount('members')` / `loadCount('members')`) before returning this.
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
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'member_count' => (int) $this->members_count,
            'created_at' => $this->created_at,
        ];
    }
}
