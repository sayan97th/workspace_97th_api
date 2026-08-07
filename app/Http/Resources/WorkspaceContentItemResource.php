<?php

namespace App\Http\Resources;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A board/doc leaf from a workspace's navigation tree, listed outside that
 * tree — Manage Workspace's "Recents" and "Content" tabs. This is
 * deliberately the same {@link WorkspaceNavigationItem} rows the sidebar
 * itself renders (not board "views"/tabs): "content" here means the
 * boards/docs a user sees in their sidebar, so both surfaces always agree.
 *
 * @mixin WorkspaceNavigationItem
 */
class WorkspaceContentItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'type' => $this->type,
            'asset_type' => $this->assetType(),
            'display_style' => $this->display_style,
            'board_type' => $this->board_type,
            'icon' => $this->icon,
            'is_favorite' => $this->is_favorite,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'full_name' => $this->creator->full_name,
                'profile_photo_url' => $this->creator->profile_photo_url,
            ] : null),
            'workspace' => $this->whenLoaded('workspace', fn () => [
                'id' => $this->workspace->id,
                'slug' => $this->workspace->slug,
                'name' => $this->workspace->name,
            ]),
            // The chain of *group* ancestors from the workspace root down to
            // (not including) this item — mirrors exactly what the sidebar
            // nests this item under, empty for a root-level item.
            'folder_path' => $this->ancestors()->map(fn (WorkspaceNavigationItem $ancestor) => [
                'id' => $ancestor->id,
                'label' => $ancestor->label,
            ])->values(),
        ];
    }
}
