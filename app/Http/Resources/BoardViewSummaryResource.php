<?php

namespace App\Http\Resources;

use App\Models\BoardView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lightweight board-view summary for cross-board listings — Manage
 * Workspace's "Recents" (scoped to one workspace) and "Content" (app-wide)
 * tabs. Unlike {@link BoardViewResource} it skips filter/sort/display state
 * and instead adds the owning board + workspace, since those listings need
 * to identify/link a view outside the single-board context every other
 * board-view endpoint assumes.
 *
 * @mixin BoardView
 */
class BoardViewSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'view_type' => $this->view_type,
            'icon' => $this->icon,
            'is_primary' => $this->is_primary,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'full_name' => $this->creator->full_name,
                'profile_photo_url' => $this->creator->profile_photo_url,
            ] : null),
            'board' => $this->whenLoaded('board', fn () => [
                'id' => $this->board->id,
                'label' => $this->board->label,
                'slug' => $this->board->slug,
            ]),
            'workspace' => $this->whenLoaded('board', fn () => $this->board->relationLoaded('workspace') ? [
                'id' => $this->board->workspace->id,
                'slug' => $this->board->workspace->slug,
                'name' => $this->board->workspace->name,
            ] : null),
        ];
    }
}
