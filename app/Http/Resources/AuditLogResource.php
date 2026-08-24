<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Support\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'description' => $this->description,
            'ip_address' => $this->ip_address,
            'device' => UserAgentParser::parse($this->user_agent),
            'created_at' => $this->created_at,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'full_name' => $this->actor->full_name,
            ] : null),
        ];
    }
}
