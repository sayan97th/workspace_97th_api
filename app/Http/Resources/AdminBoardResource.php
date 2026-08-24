<?php

namespace App\Http\Resources;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of Administration > Board ownership's tables (current-owner picker, orphan
 * boards list) — a much smaller payload than {@see WorkspaceNavigationItemResource}, this
 * screen only needs identity, not the board's full content tree.
 *
 * @mixin WorkspaceNavigationItem
 */
class AdminBoardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'workspace_id' => $this->workspace_id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id,
                'full_name' => $this->owner->full_name,
            ] : null),
        ];
    }
}
