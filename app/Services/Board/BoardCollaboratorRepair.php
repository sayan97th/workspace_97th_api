<?php

namespace App\Services\Board;

use App\Models\WorkspaceNavigationItem;
use App\Support\VisibleBoards;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds people who were given a board but are missing from `board_collaborators`, the one
 * table {@see VisibleBoards} reads to let someone into a private board.
 *
 * Two grants should always leave a collaborator row behind:
 * - an accepted board invitation, for the account whose email matches it (any letter case);
 * - a team that joined the board, for every member of that team.
 *
 * A row can still go missing (an account that was recreated, an email changed after the
 * invitation, data restored from a backup), and then the person is listed by the invite
 * dialog's history but gets "This board is private". {@see repair()} puts those rows back.
 */
class BoardCollaboratorRepair
{
    /**
     * Every missing collaborator row, optionally limited to some boards.
     *
     * @param  array<int, int>  $board_ids  empty for every board
     * @return Collection<int, array{board_id: int, user_id: int, invited_by: int|null, source: string}>
     */
    public function findMissing(array $board_ids = []): Collection
    {
        $from_invitations = DB::table('board_invitations')
            ->join('users', DB::raw('LOWER(users.email)'), '=', DB::raw('LOWER(board_invitations.email)'))
            ->leftJoin('board_collaborators', fn ($join) => $join
                ->on('board_collaborators.board_id', '=', 'board_invitations.board_id')
                ->on('board_collaborators.user_id', '=', 'users.id'))
            ->whereNotNull('board_invitations.accepted_at')
            ->whereNull('users.deleted_at')
            ->whereNull('board_collaborators.id')
            ->when($board_ids !== [], fn ($query) => $query->whereIn('board_invitations.board_id', $board_ids))
            ->select('board_invitations.board_id', 'users.id as user_id', 'board_invitations.invited_by')
            ->get()
            ->map(fn ($row) => [
                'board_id' => (int) $row->board_id,
                'user_id' => (int) $row->user_id,
                'invited_by' => $row->invited_by !== null ? (int) $row->invited_by : null,
                'source' => 'accepted invitation',
            ]);

        $from_teams = DB::table('account_team_boards')
            ->join('account_team_user', 'account_team_user.account_team_id', '=', 'account_team_boards.account_team_id')
            ->join('users', 'users.id', '=', 'account_team_user.user_id')
            ->leftJoin('board_collaborators', fn ($join) => $join
                ->on('board_collaborators.board_id', '=', 'account_team_boards.board_id')
                ->on('board_collaborators.user_id', '=', 'account_team_user.user_id'))
            ->whereNull('users.deleted_at')
            ->whereNull('board_collaborators.id')
            ->when($board_ids !== [], fn ($query) => $query->whereIn('account_team_boards.board_id', $board_ids))
            ->select('account_team_boards.board_id', 'account_team_user.user_id')
            ->get()
            ->map(fn ($row) => [
                'board_id' => (int) $row->board_id,
                'user_id' => (int) $row->user_id,
                'invited_by' => null,
                'source' => 'team access',
            ]);

        return $from_invitations
            ->concat($from_teams)
            ->unique(fn (array $row) => "{$row['board_id']}-{$row['user_id']}")
            ->values();
    }

    /**
     * Adds the missing collaborator rows and returns them. Running it twice changes nothing.
     *
     * @param  array<int, int>  $board_ids  empty for every board
     * @return Collection<int, array{board_id: int, user_id: int, invited_by: int|null, source: string}>
     */
    public function repair(array $board_ids = []): Collection
    {
        $missing = $this->findMissing($board_ids);

        $missing->groupBy('board_id')->each(function (Collection $rows, int $board_id) {
            $board = WorkspaceNavigationItem::withoutGlobalScopes()->find($board_id);
            if ($board === null) {
                return;
            }

            $board->collaborators()->syncWithoutDetaching(
                $rows->mapWithKeys(fn (array $row) => [$row['user_id'] => ['invited_by' => $row['invited_by']]])->all()
            );
        });

        return $missing;
    }
}
