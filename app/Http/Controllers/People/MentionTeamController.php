<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\AccountTeam;
use App\Models\WorkspaceNavigationItem;
use App\Support\BoardMembers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MentionTeamController extends Controller
{
    /** Same cap as the comment endpoints put on `mentioned_user_ids`. */
    private const MAX_MEMBERS = 200;

    /**
     * GET /api/people/boards/{board}/teams
     *
     * The account teams that can be group `@mentioned` on `{board}`, each with
     * the ids of its members who were added to the board ({@see BoardMembers}).
     * Everyone else is left out, so a team mention can never reach someone
     * who could not open the board. Teams with nobody on the board are omitted, and the list is open to every workspace member (unlike the
     * staff-only Teams directory), since it exposes only names and ids.
     */
    public function index(Request $request, WorkspaceNavigationItem $board): JsonResponse
    {
        abort_unless($request->user()->workspaces()->where('workspaces.id', $board->workspace_id)->exists(), 403);

        $board_member_ids = BoardMembers::ids($board);

        $member_ids_by_team = DB::table('account_team_user')
            ->whereIn('user_id', $board_member_ids)
            ->get(['account_team_id', 'user_id'])
            ->groupBy('account_team_id')
            ->map(fn ($rows) => $rows->pluck('user_id')->map(fn ($user_id) => (int) $user_id)->values());

        $teams = AccountTeam::query()
            ->whereIn('id', $member_ids_by_team->keys())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AccountTeam $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'member_ids' => $member_ids_by_team[$team->id]->take(self::MAX_MEMBERS)->values(),
            ])
            ->values();

        return response()->json(['data' => $teams]);
    }
}
