<?php

namespace App\Http\Resources;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkspaceNavigationItem
 */
class WorkspaceNavigationItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'parent_id' => $this->parent_id,
            'type' => $this->type,
            'label' => $this->label,
            'description' => $this->description,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'view_key' => $this->view_key,
            'href' => $this->href,
            'display_style' => $this->display_style,
            'board_type' => $this->board_type,
            'item_column_label' => $this->item_column_label,
            'item_column_width' => $this->item_column_width,
            'sub_item_column_width' => $this->sub_item_column_width,
            'is_favorite' => $this->is_favorite,
            // Total updates (top-level + replies) on the board's discussion feed, powering the "Board updates"
            // badge; 0 unless the caller ran loadCount('comments') first (only BoardController::show() does).
            'comments_count' => $this->whenCounted('comments', default: 0),
            'position' => $this->position,
            'created_at' => $this->created_at,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'full_name' => $this->creator->full_name,
                'profile_photo_url' => $this->creator->profile_photo_url,
            ] : null),
            // Boards don't have their own owner list, so they inherit the workspace's owners.
            'owners' => $this->relationLoaded('workspace') && $this->workspace->relationLoaded('owners')
                ? $this->workspace->owners->map(fn ($owner) => [
                    'id' => $owner->id,
                    'full_name' => $owner->full_name,
                    'profile_photo_url' => $owner->profile_photo_url,
                ])->values()
                : [],
            'children' => self::collection($this->whenLoaded('childrenRecursive')),
        ];
    }
}
