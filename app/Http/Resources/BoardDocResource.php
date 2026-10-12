<?php

namespace App\Http\Resources;

use App\Models\BoardDoc;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BoardDoc
 */
class BoardDocResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'board_id' => $this->board_id,
            'content' => $this->content,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_by' => $this->updatedBy ? [
                'id' => $this->updatedBy->id,
                'full_name' => $this->updatedBy->full_name,
                'profile_photo_url' => $this->updatedBy->profile_photo_url,
            ] : null,
        ];
    }
}
