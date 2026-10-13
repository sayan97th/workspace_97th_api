<?php

namespace App\Http\Resources;

use App\Models\BoardColumn;
use App\Services\Board\ColumnLinkageResolver;
use App\Services\Board\ColumnPermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BoardColumn
 */
class BoardColumnResource extends JsonResource
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
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'scope' => $this->scope,
            'position' => $this->position,
            'width' => $this->width,
            'config' => $this->config,
            'hideable' => $this->hideable,
            'pinnable' => $this->pinnable,
            // Column permissions: `null` means everyone, otherwise
            // `{user_ids, team_ids}` (board owners always pass).
            'view_restriction' => $this->view_restriction,
            'edit_restriction' => $this->edit_restriction,
            'can_edit_values' => $this->canEditValues($request),
            // Connect boards and Mirror columns only: what they are linked to, for the header's info popover.
            'linkage' => in_array($this->type, [BoardColumn::TYPE_CONNECT_BOARD, BoardColumn::TYPE_MIRROR], true)
                ? app(ColumnLinkageResolver::class)->resolve($this->resource, $request->user())
                : null,
        ];
    }

    /**
     * Whether the requesting user may change this column's cells.
     */
    private function canEditValues(Request $request): bool
    {
        if ($this->edit_restriction === null && $this->view_restriction === null) {
            return true;
        }

        $board = $this->relationLoaded('board') ? $this->board : $this->board()->first();

        return $board !== null && app(ColumnPermissionService::class)->canEdit($this->resource, $request->user(), $board);
    }
}
