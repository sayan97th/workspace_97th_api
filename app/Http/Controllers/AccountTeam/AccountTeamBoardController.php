<?php

namespace App\Http\Controllers\AccountTeam;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountTeam\LinkAccountTeamBoardsRequest;
use App\Http\Resources\AccountTeamBoardResource;
use App\Http\Resources\AccountTeamResource;
use App\Models\AccountTeam;
use App\Models\WorkspaceNavigationItem;
use App\Services\AccountTeam\AccountTeamBoardAccess;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A team's Content tab: the boards the team joined. Joining a board gives every member access
 * to it ({@see AccountTeamBoardAccess}). A team that allows guests may only join shareable boards,
 * since guests can never open a main or private board.
 */
class AccountTeamBoardController extends Controller
{
    private const MAX_CANDIDATES = 50;

    public function __construct(private readonly AccountTeamBoardAccess $board_access) {}

    /**
     * GET /api/account-teams/{team}/boards
     */
    public function index(AccountTeam $team): JsonResponse
    {
        $boards = $team->boards()
            ->with('workspace:id,name')
            ->orderBy('label')
            ->get();

        return response()->json([
            'data' => AccountTeamBoardResource::collection($boards),
        ]);
    }

    /**
     * GET /api/account-teams/{team}/board-candidates
     *
     * Boards the team could join, searched by name, leaving out the ones it already joined,
     * archived boards and templates.
     */
    public function candidates(Request $request, AccountTeam $team): JsonResponse
    {
        $query = $this->linkableBoards($team)
            ->whereDoesntHave('accountTeams', fn (Builder $teams) => $teams->where('account_teams.id', $team->id))
            ->with('workspace:id,name')
            ->orderBy('label');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where('label', 'LIKE', '%'.$search.'%');
        }

        return response()->json([
            'data' => AccountTeamBoardResource::collection($query->limit(self::MAX_CANDIDATES)->get()),
        ]);
    }

    /**
     * POST /api/account-teams/{team}/boards
     */
    public function store(LinkAccountTeamBoardsRequest $request, AccountTeam $team): JsonResponse
    {
        $actor = $request->user();
        abort_unless($team->canBeManagedBy($actor), 403, 'You cannot manage this team.');

        $board_ids = array_map('intval', $request->validated('board_ids'));
        $boards = $this->linkableBoards($team)->whereIn('id', $board_ids)->get();

        if ($boards->count() !== count($board_ids)) {
            throw ValidationException::withMessages([
                'board_ids' => $team->allow_guests
                    ? 'A team with guests can only join shareable boards.'
                    : 'Some of these boards cannot be added to a team.',
            ]);
        }

        $team->boards()->syncWithoutDetaching(
            $boards->mapWithKeys(fn (WorkspaceNavigationItem $board) => [$board->id => ['added_by_id' => $actor->id]])->all()
        );
        $this->board_access->grantBoards($team, $boards, $actor);

        AuditLogger::log(
            'team.boards_added',
            'Added '.$boards->count()." board(s) to team \"{$team->name}\".",
            $actor,
            ['team_id' => $team->id, 'board_ids' => $board_ids],
        );

        return response()->json([
            'message' => 'Boards added successfully.',
            'team' => new AccountTeamResource($team->loadResourceData($actor)),
        ]);
    }

    /**
     * DELETE /api/account-teams/{team}/boards/{board}
     *
     * Takes the board off the team's Content tab. Members keep the access they were given.
     */
    public function destroy(Request $request, AccountTeam $team, WorkspaceNavigationItem $board): JsonResponse
    {
        $actor = $request->user();
        abort_unless($team->canBeManagedBy($actor), 403, 'You cannot manage this team.');

        $team->boards()->detach($board->id);

        AuditLogger::log(
            'team.board_removed',
            "Removed board \"{$board->label}\" from team \"{$team->name}\".",
            $actor,
            ['team_id' => $team->id, 'board_id' => $board->id],
        );

        return response()->json([
            'message' => 'Board removed successfully.',
            'team' => new AccountTeamResource($team->loadResourceData($actor)),
        ]);
    }

    /**
     * @return Builder<WorkspaceNavigationItem>
     */
    private function linkableBoards(AccountTeam $team): Builder
    {
        $query = WorkspaceNavigationItem::query()->boards()->notArchived();

        if ($team->allow_guests) {
            $query->where('board_type', WorkspaceNavigationItem::BOARD_TYPE_SHAREABLE);
        }

        return $query;
    }
}
