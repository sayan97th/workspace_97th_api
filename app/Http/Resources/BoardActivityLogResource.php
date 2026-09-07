<?php

namespace App\Http\Resources;

use App\Models\BoardActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BoardActivityLog
 */
class BoardActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'description' => $this->description,
            'meta' => $this->meta,
            'created_at' => $this->created_at,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'full_name' => $this->user->full_name,
                'profile_photo_url' => $this->user->profile_photo_url,
            ] : null,
        ];
    }
}
