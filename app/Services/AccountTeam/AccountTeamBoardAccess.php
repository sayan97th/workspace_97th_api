<?php

namespace App\Services\AccountTeam;

use App\Models\AccountTeam;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;

/**
 * When a team joins a board, its members become that board's collaborators, the same access a
 * board invitation grants. People who join the team later get the team's boards too. Leaving a
 * team or unlinking a board does not revoke anything, since the person may have been given the
 * board on their own as well.
 */
class AccountTeamBoardAccess
{
    /**
     * Grants every member of the team access to the given boards.
     *
     * @param  iterable<WorkspaceNavigationItem>  $boards
     */
    public function grantBoards(AccountTeam $team, iterable $boards, User $actor): void
    {
        $member_ids = $team->members()->pluck('users.id')->all();

        foreach ($boards as $board) {
            $this->attachCollaborators($board, $member_ids, $actor);
        }
    }

    /**
     * Grants the given new members access to every board the team already joined.
     *
     * @param  array<int, int>  $member_ids
     */
    public function grantMembers(AccountTeam $team, array $member_ids, User $actor): void
    {
        if ($member_ids === []) {
            return;
        }

        foreach ($team->boards()->get() as $board) {
            $this->attachCollaborators($board, $member_ids, $actor);
        }
    }

    /**
     * @param  array<int, int>  $member_ids
     */
    private function attachCollaborators(WorkspaceNavigationItem $board, array $member_ids, User $actor): void
    {
        $existing_ids = $board->collaborators()->pluck('users.id')->all();
        $new_ids = array_values(array_diff($member_ids, $existing_ids));
        if ($new_ids === []) {
            return;
        }

        $board->collaborators()->attach(
            collect($new_ids)->mapWithKeys(fn (int $id) => [$id => ['invited_by' => $actor->id]])->all()
        );
    }
}
