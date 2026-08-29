<?php

namespace App\Http\Resources;

use App\Models\BoardColumn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BoardColumn
 */
class BoardColumnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'board_view_id' => $this->board_view_id,
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'scope' => $this->scope,
            'position' => $this->position,
            'width' => $this->width,
            'config' => $this->config,
            'hideable' => $this->hideable,
            'pinnable' => $this->pinnable,
        ];
    }
}
