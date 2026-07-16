<?php

namespace App\Http\Resources;

use App\Models\BoardItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single item resolved for the item detail drawer at
 * `/boards/{board_id}/pulses/{id}` — extends the plain {@link BoardItemResource}
 * payload with the fields the drawer's Info Boxes tab
 * shows (created by, created at, group). There is no comments/activity
 * backend yet, so those tabs stay client-side-only for now.
 *
 * @mixin BoardItem
 */
class BoardItemDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = (new BoardItemResource($this->resource))->toArray($request);

        return array_merge($item, [
            'created_at' => $this->created_at,
            'group' => [
                'id' => $this->group->id,
                'name' => $this->group->name,
                'accent_color' => $this->group->accent_color,
            ],
            'creator' => $this->creator ? [
                'id' => $this->creator->id,
                'full_name' => $this->creator->full_name,
                'profile_photo_url' => $this->creator->profile_photo_url,
            ] : null,
        ]);
    }
}
