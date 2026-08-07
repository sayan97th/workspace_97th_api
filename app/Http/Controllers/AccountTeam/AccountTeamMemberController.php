<?php

namespace App\Http\Controllers\AccountTeam;

use App\Concerns\ValidatesStaffMemberIds;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountTeam\SyncAccountTeamMembersRequest;
use App\Http\Resources\AccountTeamMemberResource;
use App\Models\AccountTeam;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountTeamMemberController extends Controller
{
    use ValidatesStaffMemberIds;

    private const MAX_PER_PAGE = 100;

    /**
     * GET /api/account-teams/{team}/members
     *
     * A single team's roster (the Teams modal's "Users" tab for a selected
     * team), searched and paginated server-side.
     */
    public function forTeam(Request $request, AccountTeam $team): JsonResponse
    {
        $query = $team->members()
            ->with('roles:id,name')
            ->orderBy('first_name')
            ->orderBy('last_name');

        $this->applySearch($query, $request->query('search'));

        return response()->json($this->paginate($query, $request));
    }

    /**
     * PUT /api/account-teams/{team}/members
     *
     * Replaces the team's roster wholesale with `member_ids` — simpler than
     * add/remove diff endpoints, and matches how the "Edit team" dialog's
     * member picker always submits the full selection.
     */
    public function sync(SyncAccountTeamMembersRequest $request, AccountTeam $team): JsonResponse
    {
        $team->members()->sync($request->validated('member_ids'));

        return response()->json([
            'message' => 'Team members updated successfully.',
        ]);
    }

    /**
     * GET /api/account-team-members
     *
     * The account-wide "All members" dedupe: every staff user who belongs to
     * at least one team, once each — not literally every staff account (a
     * new hire with no team yet stays absent until they're added to one).
     */
    public function all(Request $request): JsonResponse
    {
        $query = User::whereHas('accountTeams')
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', self::staffRoles()))
            ->with('roles:id,name')
            ->orderBy('first_name')
            ->orderBy('last_name');

        $this->applySearch($query, $request->query('search'));

        return response()->json([
            ...$this->paginate($query, $request),
            'team_count' => AccountTeam::count(),
        ]);
    }

    /**
     * GET /api/account-team-candidates
     *
     * The staff directory a team's member picker searches against — every
     * staff user regardless of existing team membership.
     */
    public function candidates(Request $request): JsonResponse
    {
        $query = User::whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', self::staffRoles()))
            ->with('roles:id,name')
            ->orderBy('first_name')
            ->orderBy('last_name');

        $this->applySearch($query, $request->query('search'));

        return response()->json($this->paginate($query, $request, default_per_page: self::MAX_PER_PAGE));
    }

    /**
     * @param  Builder<User>|BelongsToMany<User, AccountTeam>  $query
     */
    private function applySearch(Builder|BelongsToMany $query, ?string $search): void
    {
        if ($search === null || $search === '') {
            return;
        }

        $query->where(function (Builder $inner) use ($search) {
            $inner->where('first_name', 'LIKE', '%'.$search.'%')
                ->orWhere('last_name', 'LIKE', '%'.$search.'%')
                ->orWhere('email', 'LIKE', '%'.$search.'%');
        });
    }

    /**
     * @param  Builder<User>|BelongsToMany<User, AccountTeam>  $query
     * @return array<string, mixed>
     */
    private function paginate(Builder|BelongsToMany $query, Request $request, int $default_per_page = 20): array
    {
        $per_page = min((int) $request->query('per_page', $default_per_page), self::MAX_PER_PAGE);

        /** @var LengthAwarePaginator<int, User> $members */
        $members = $query->paginate(max($per_page, 1));

        return [
            'data' => AccountTeamMemberResource::collection($members->items()),
            'current_page' => $members->currentPage(),
            'last_page' => $members->lastPage(),
            'total' => $members->total(),
        ];
    }
}
