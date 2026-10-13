<?php

namespace App\Services\Search\Everything;

use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * Shapes the models every tab returns, so a board, item, person or workspace always looks the
 * same in the payload.
 */
final class SearchPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function board(WorkspaceNavigationItem $board): array
    {
        return [
            'id' => $board->id,
            'label' => $board->label,
            'asset_type' => $board->assetType(),
            'board_type' => $board->board_type ?? WorkspaceNavigationItem::BOARD_TYPE_MAIN,
            'is_archived' => (bool) $board->is_archived,
            'workspace' => $board->workspace ? self::workspace($board->workspace) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function workspace(Workspace $workspace): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'mono' => $workspace->mono,
            'color' => $workspace->color,
            'avatar_url' => $workspace->avatar_thumbnail_url ?? $workspace->avatar_url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(BoardItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'is_subitem' => $item->parent_id !== null,
            // Tab (board view) the item lives on, needed to open its drawer in the right tab.
            'view_id' => $item->group?->board_view_id,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function person(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'photo_url' => $user->profile_photo_url,
            'is_deactivated' => $user->is_deactivated,
        ];
    }
}
