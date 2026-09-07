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
            'board_view_id' => $this->board_view_id,
            'name' => $this->name,
            'accent_color' => $this->accent_color,
            'is_priority' => $this->is_priority,
            'position' => $this->position,
            // Root item count (excludes subitems/archived), from `withCount`
            // in `BoardGroupController::index()` — lets the frontend size a
            // table's "N items" label and loading skeleton before its rows
            // are actually fetched (see `GroupSection`'s lazy loading).
            // Falls back to 0 for any other call site that doesn't eager-load it.
            'item_count' => $this->item_count ?? 0,
        ];
    }
}
