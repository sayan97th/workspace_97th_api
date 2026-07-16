<?php

namespace App\Http\Resources;

use App\Models\BoardGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BoardGroup
 */
class BoardGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'name' => $this->name,
            'accent_color' => $this->accent_color,
            'position' => $this->position,
        ];
    }
}
