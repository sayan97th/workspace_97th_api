<?php

namespace App\Http\Resources;

use App\Models\UserSession;
use App\Support\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of Administration > Sessions, the admin-wide sibling of the self-service session
 * list `Profile\UserSessionController::index()` builds inline. Formalized as a real Resource
 * here since it's now reused across two controllers.
 *
 * @mixin UserSession
 */
class AdminUserSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device' => $this->device_label ?? UserAgentParser::parse($this->user_agent),
            'ip_address' => $this->ip_address,
            'last_used_at' => $this->last_used_at,
            'is_revoked' => $this->revoked_at !== null,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'full_name' => $this->user->full_name,
                'profile_photo_url' => $this->user->profile_photo_url,
            ] : null),
        ];
    }
}
