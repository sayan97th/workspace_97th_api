<?php

namespace App\Http\Resources;

use App\Models\BoardItemActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One cell change of a board item, for the item card's Activity Log tab.
 *
 * @mixin BoardItemActivity
 */
class BoardItemActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->user !== null ? [
                'id' => $this->user->id,
                'full_name' => $this->user->full_name,
                'profile_photo_url' => $this->user->profile_photo_url,
                'is_deactivated' => $this->user->is_deactivated,
            ] : null,
            // Set when an automation made the change, the log then credits it instead of the user.
            'automation' => $this->automation_name !== null ? ['id' => $this->automation_id, 'name' => $this->automation_name] : null,
            'column_id' => $this->column_id,
            'column_label' => $this->column_label,
            'column_type' => $this->column_type,
            'old_display' => $this->old_display,
            'new_display' => $this->new_display,
            'created_at' => $this->created_at,
        ];
    }
}
