<?php

namespace App\Http\Resources;

use App\Models\WorkspaceInvitation;
use App\Support\WorkspacePermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkspaceInvitation
 */
class WorkspaceInvitationResource extends JsonResource
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
            'role_label' => WorkspacePermissionCatalog::labelFor($this->role),
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
