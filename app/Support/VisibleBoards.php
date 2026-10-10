<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which boards, docs and dashboards a user may open, shared by global search, the account
 * wide Automations center and the sidebar (tree, Favorites, Recent): not archived, in a workspace that still exists, not the special
 * "Manage Workspace" leaf, and, for a private board, only when the user created or owns it, was
 * added to it as a collaborator, or holds a privileged global role.
 */
final class VisibleBoards
{
    /** "Manage Workspace" is itself a navigation leaf, not real content, never list it. */
    private const MANAGE_WORKSPACE_VIEW_KEY = 'workspace_manage';

    /** Global roles that can see every board, including private ones they were never added to. */
    private const PRIVILEGED_GLOBAL_ROLES = ['super_admin', 'admin'];

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
            ->tap(fn (Builder $query) => self::withoutHiddenPrivate($query, $user));
    }

    /**
     * Applies only the private board rule to any navigation item query, so lists that keep
     * their own filters (sidebar tree, Favorites, Recent) hide the same private boards as
     * {@see query()}. Folders and non private boards always pass.
     *
     * @param  Builder<WorkspaceNavigationItem>  $query
     * @return Builder<WorkspaceNavigationItem>
     */
    public static function withoutHiddenPrivate(Builder $query, User $user): Builder
    {
        return $query->unless($user->hasRole(self::PRIVILEGED_GLOBAL_ROLES), fn (Builder $query) => $query->where(
            fn (Builder $visibility) => $visibility->where('board_type', '!=', WorkspaceNavigationItem::BOARD_TYPE_PRIVATE)
                ->orWhere('created_by_id', $user->id)
                ->orWhere('owner_id', $user->id)
                ->orWhereHas('collaborators', fn (Builder $collaborators) => $collaborators->where('users.id', $user->id))
        ));
    }

    /**
     * Ids of the private boards in a workspace the user may not see, for filtering an already
     * loaded tree without one query per node.
     *
     * @return array<int, true>
     */
    public static function hiddenPrivateIds(User $user, int $workspace_id): array
    {
        if ($user->hasRole(self::PRIVILEGED_GLOBAL_ROLES)) {
            return [];
        }

        $private_boards = WorkspaceNavigationItem::query()
            ->where('workspace_id', $workspace_id)
            ->where('board_type', WorkspaceNavigationItem::BOARD_TYPE_PRIVATE);

        $visible_ids = self::withoutHiddenPrivate($private_boards->clone(), $user)->pluck('id');

        return $private_boards->whereNotIn('id', $visible_ids)->pluck('id')->flip()->map(fn () => true)->all();
    }
}
