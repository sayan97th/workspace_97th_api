<?php

namespace App\Http\Resources;

use App\Models\BoardItemChecklistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a board item's subtask checklist.
 *
 * @mixin BoardItemChecklistItem
 */
class BoardItemChecklistItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'label' => $this->label,
            'is_done' => $this->is_done,
            'position' => $this->position,
        ];
    }
}
