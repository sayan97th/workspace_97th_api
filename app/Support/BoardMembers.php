<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The people who were added to a board, so the only ones who may be assigned in its People
 * columns, `@mentioned` or notified in its updates and discussion, or picked anywhere else on it.
 *
 * It is the same rule as {@see VisibleBoards::canView()}, seen from the board's side: the board's
 * creator, its owner and its collaborators (board invitations, team access), plus every member of
 * its workspace unless the board is private. Disabled and deleted accounts are never members.
 *
 * Writes filter the ids they receive through {@see onlyMembers()}, so a stale picker or a hand made
 * request can never assign or notify someone who cannot open the board.
 */
final class BoardMembers
{
    /**
     * @return Builder<User>
     */
    public static function query(WorkspaceNavigationItem $item): Builder
    {
        $is_private = $item->board_type === WorkspaceNavigationItem::BOARD_TYPE_PRIVATE;

        return User::query()
            ->where('users.is_active', true)
            ->where(fn (Builder $members) => $members
                ->whereIn('users.id', array_values(array_filter([$item->created_by_id, $item->owner_id])))
                ->orWhereIn('users.id', DB::table('board_collaborators')->select('user_id')->where('board_id', $item->id))
                ->when(! $is_private, fn (Builder $query) => $query->orWhereIn(
                    'users.id',
                    DB::table('workspace_user')->select('user_id')->where('workspace_id', $item->workspace_id)
                )));
    }

    /**
     * @return Collection<int, int>
     */
    public static function ids(WorkspaceNavigationItem $item): Collection
    {
        return self::query($item)->pluck('users.id')->map(fn ($id) => (int) $id)->values();
    }

    /**
     * Keeps only the given user ids that belong to the board, in their original order and without
     * duplicates. Anything that is not a whole number is dropped too.
     *
     * @param  iterable<int, mixed>  $user_ids
     * @return Collection<int, int>
     */
    public static function onlyMembers(WorkspaceNavigationItem $item, iterable $user_ids): Collection
    {
        $requested_ids = collect($user_ids)
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($requested_ids->isEmpty()) {
            return $requested_ids;
        }

        $member_ids = self::query($item)
            ->whereIn('users.id', $requested_ids->all())
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        return $requested_ids->filter(fn (int $id) => $member_ids->has($id))->values();
    }

    public static function includes(WorkspaceNavigationItem $item, int $user_id): bool
    {
        return self::query($item)->whereKey($user_id)->exists();
    }
}
