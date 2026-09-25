<?php

namespace App\Http\Resources;

use App\Models\WorkspaceNavigationItem;
use App\Support\Admin\AdminBoardQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One board row of Administration > Content directory and Tidy up. `last_activity_at` and
 * `items_count` come from the subselects {@see AdminBoardQuery} adds.
 *
 * @mixin WorkspaceNavigationItem
 */
class AdminContentBoardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $last_activity = $this->resource->getAttribute('last_activity_at');

        return [
            'id' => $this->id,
            'label' => $this->label,
            'board_type' => $this->board_type ?? WorkspaceNavigationItem::BOARD_TYPE_MAIN,
            'is_archived' => (bool) $this->is_archived,
            'archived_at' => $this->archived_at,
            'items_count' => (int) $this->resource->getAttribute('items_count'),
            'created_at' => $this->created_at,
            'last_activity_at' => $last_activity ? Carbon::parse($last_activity)->toIso8601String() : null,
            'workspace' => $this->whenLoaded('workspace', fn () => $this->workspace ? [
                'id' => $this->workspace->id,
                'name' => $this->workspace->name,
            ] : null),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id,
                'full_name' => $this->owner->full_name,
                'profile_photo_url' => $this->owner->profile_photo_url,
                'is_deactivated' => $this->owner->is_deactivated,
            ] : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'full_name' => $this->creator->full_name,
            ] : null),
        ];
    }
}
