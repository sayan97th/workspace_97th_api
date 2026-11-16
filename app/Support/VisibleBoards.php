<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which boards, docs and dashboards a user may open, shared by global search, the account
 * wide Automations center and the sidebar (tree, Favorites, Recent): not archived, in a workspace that still exists, not the special
 * "Manage Workspace" leaf, and, for a private board, only when the user created or owns it, or was
 * added to it as a collaborator.
 *
 * Like monday.com, a private board is never visible through a global role: account admins and
 * workspace owners do not see the private boards of other people unless they were added to them.
 */
final class VisibleBoards
{
    /** "Manage Workspace" is itself a navigation leaf, not real content, never list it. */
    private const MANAGE_WORKSPACE_VIEW_KEY = 'workspace_manage';

    /**
     * Pass `$include_archived` to keep archived boards too, the "Search archives" option of
     * Search Everything uses it. Every other rule still applies.
     *
     * @return Builder<WorkspaceNavigationItem>
     */
    public static function query(User $user, bool $include_archived = false): Builder
    {
        return WorkspaceNavigationItem::query()
            ->where('type', WorkspaceNavigationItem::TYPE_LEAF)
            ->unless($include_archived, fn (Builder $query) => $query->notArchived())
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
        return $query->where(
            fn (Builder $visibility) => $visibility->where('board_type', '!=', WorkspaceNavigationItem::BOARD_TYPE_PRIVATE)
                ->orWhere('created_by_id', $user->id)
                ->orWhere('owner_id', $user->id)
                ->orWhereHas('collaborators', fn (Builder $collaborators) => $collaborators->where('users.id', $user->id))
        );
    }

    /**
     * Whether the user may see this one item, the same rule as {@see withoutHiddenPrivate()}
     * for a model that is already loaded (direct board links, board endpoints).
     */
    public static function canView(User $user, WorkspaceNavigationItem $item): bool
    {
        if ($item->board_type !== WorkspaceNavigationItem::BOARD_TYPE_PRIVATE) {
            return true;
        }

        if ($item->created_by_id === $user->id || $item->owner_id === $user->id) {
            return true;
        }

        return $item->collaborators()->where('users.id', $user->id)->exists();
    }

    /**
     * Keeps only the users who may open the board, in their original order. A board that is not
     * private keeps everyone. Used wherever a private board reaches people (notifications, emails,
     * Slack messages), so nobody outside a private board ever hears about its items.
     *
     * @template TUser of User
     *
     * @param  iterable<int, TUser>  $users
     * @return Collection<int, TUser>
     */
    public static function onlyViewers(WorkspaceNavigationItem $item, iterable $users): Collection
    {
        $users = collect($users)->values();

        if ($item->board_type !== WorkspaceNavigationItem::BOARD_TYPE_PRIVATE || $users->isEmpty()) {
            return $users;
        }

        $collaborator_ids = $item->collaborators()
            ->whereIn('users.id', $users->pluck('id')->all())
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        return $users->filter(fn (User $user) => $user->id === $item->created_by_id
            || $user->id === $item->owner_id
            || $collaborator_ids->has((int) $user->id))->values();
    }

    /**
     * Restricts a notifications query to rows its recipient may still see: no board, a board that
     * is not private, or a private board the recipient created, owns or was added to. It compares
     * against each row's own `user_id`, so it works for any recipient without passing one in, and
     * a person removed from a private board stops seeing its old notifications too.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public static function notificationsOnVisibleBoards(Builder $query): Builder
    {
        $notifications = $query->getModel()->getTable();
        $boards = (new WorkspaceNavigationItem)->getTable();

        return $query->where(fn (Builder $visibility) => $visibility
            ->whereNull("{$notifications}.board_id")
            ->orWhereNotExists(fn ($private_board) => $private_board
                ->selectRaw('1')
                ->from($boards)
                ->whereColumn("{$boards}.id", "{$notifications}.board_id")
                ->where("{$boards}.board_type", WorkspaceNavigationItem::BOARD_TYPE_PRIVATE)
                ->where(fn ($not_creator) => $not_creator
                    ->whereNull("{$boards}.created_by_id")
                    ->orWhereColumn("{$boards}.created_by_id", '!=', "{$notifications}.user_id"))
                ->where(fn ($not_owner) => $not_owner
                    ->whereNull("{$boards}.owner_id")
                    ->orWhereColumn("{$boards}.owner_id", '!=', "{$notifications}.user_id"))
                ->whereNotExists(fn ($collaborator) => $collaborator
                    ->selectRaw('1')
                    ->from('board_collaborators')
                    ->whereColumn('board_collaborators.board_id', "{$boards}.id")
                    ->whereColumn('board_collaborators.user_id', "{$notifications}.user_id"))));
    }

    /**
     * Whether the user may switch this item to private. Only the people who keep seeing a
     * private board without being invited (its creator and its owner) may, so nobody can hide
     * a board from its own creator or lock themselves out of it by accident.
     */
    public static function canMakePrivate(User $user, WorkspaceNavigationItem $item): bool
    {
        return $item->created_by_id === $user->id || $item->owner_id === $user->id;
    }

    /**
     * Ids of the private boards in a workspace the user may not see, for filtering an already
     * loaded tree without one query per node.
     *
     * @return array<int, true>
     */
    public static function hiddenPrivateIds(User $user, int $workspace_id): array
    {
        $private_boards = WorkspaceNavigationItem::query()
            ->where('workspace_id', $workspace_id)
            ->where('board_type', WorkspaceNavigationItem::BOARD_TYPE_PRIVATE);

        $visible_ids = self::withoutHiddenPrivate($private_boards->clone(), $user)->pluck('id');

        return $private_boards->whereNotIn('id', $visible_ids)->pluck('id')->flip()->map(fn () => true)->all();
    }
}
