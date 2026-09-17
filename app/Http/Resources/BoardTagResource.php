<?php

namespace App\Http\Resources;

use App\Models\BoardTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BoardTag
 */
class BoardTagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'label' => $this->label,
            'color' => $this->color,
            'position' => $this->position,
        ];
    }
}
