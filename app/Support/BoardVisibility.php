<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\DashboardDataService;
use App\Services\Search\GlobalSearchService;
use Illuminate\Database\Eloquent\Builder;

/**
 * The boards and docs a user may open: not archived, in a workspace that
 * still exists, not the special "Manage Workspace" leaf, and, for a private
 * board, only when the user created or owns it, was added to it as a
 * collaborator, or holds a privileged global role. Shared by
 * {@see GlobalSearchService} and the Dashboard widgets that read other boards
 * ({@see DashboardDataService}).
 */
class BoardVisibility
{
    public const MANAGE_WORKSPACE_VIEW_KEY = 'workspace_manage';

    public const PRIVILEGED_GLOBAL_ROLES = ['super_admin', 'admin'];

    /**
     * @return Builder<WorkspaceNavigationItem>
     */
    public static function query(User $user): Builder
    {
        return WorkspaceNavigationItem::query()
            ->where('type', WorkspaceNavigationItem::TYPE_LEAF)
            ->notArchived()
            ->whereHas('workspace')
            ->where(fn (Builder $query) => $query->where('view_key', '!=', self::MANAGE_WORKSPACE_VIEW_KEY)
                ->orWhereNull('view_key'))
            ->unless($user->hasRole(self::PRIVILEGED_GLOBAL_ROLES), fn (Builder $query) => $query->where(
                fn (Builder $visibility) => $visibility->where('board_type', '!=', WorkspaceNavigationItem::BOARD_TYPE_PRIVATE)
                    ->orWhere('created_by_id', $user->id)
                    ->orWhere('owner_id', $user->id)
                    ->orWhereHas('collaborators', fn (Builder $collaborators) => $collaborators->where('users.id', $user->id))
            ));
    }

    public static function canSee(User $user, int $board_id): bool
    {
        return self::query($user)->whereKey($board_id)->exists();
    }
}
