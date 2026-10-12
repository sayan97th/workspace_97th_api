<?php

namespace App\Http\Resources;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A board on a team's Content tab, or a candidate in its "Add boards" picker. The controller
 * eager-loads `workspace:id,name`.
 *
 * @mixin WorkspaceNavigationItem
 */
class AccountTeamBoardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->label,
            'board_type' => $this->board_type ?? WorkspaceNavigationItem::BOARD_TYPE_MAIN,
            'is_archived' => (bool) $this->is_archived,
            'workspace' => $this->workspace ? [
                'id' => (string) $this->workspace->id,
                'name' => $this->workspace->name,
            ] : null,
            'linked_at' => $this->pivot?->created_at,
        ];
    }
}
