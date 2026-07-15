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
            'slug' => $this->slug,
            'icon' => $this->icon,
            'view_key' => $this->view_key,
            'href' => $this->href,
            'display_style' => $this->display_style,
            'is_favorite' => $this->is_favorite,
            'position' => $this->position,
            'children' => self::collection($this->whenLoaded('childrenRecursive')),
        ];
    }
}
