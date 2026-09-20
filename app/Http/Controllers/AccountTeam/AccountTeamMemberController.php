<?php

namespace App\Http\Controllers\AccountTeam;

use App\Concerns\ValidatesStaffMemberIds;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountTeam\AddAccountTeamMembersRequest;
use App\Http\Requests\AccountTeam\SyncAccountTeamMembersRequest;
use App\Http\Resources\AccountTeamMemberResource;
use App\Http\Resources\AccountTeamResource;
use App\Models\AccountTeam;
use App\Models\User;
use App\Support\AuditLogger;
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
     * POST /api/account-teams/{team}/members
     *
     * Adds members to the team's existing roster without touching anyone
     * already on it, unlike `sync()`'s full-replace used by the "Edit team"
     * dialog. Backs the roster panel's "Add members" action. Admins, the account owner and the
     * team's own owners may call it.
     */
    public function store(AddAccountTeamMembersRequest $request, AccountTeam $team): JsonResponse
    {
        $actor = $request->user();
        abort_unless($team->canBeManagedBy($actor), 403, 'You cannot manage this team.');

        $member_ids = $request->validated('member_ids');
        $team->members()->syncWithoutDetaching($member_ids);
        $team->loadCount('members')->load('owners');

        AuditLogger::log(
            'team.members_added',
            'Added '.count($member_ids)." member(s) to team \"{$team->name}\".",
            $actor,
            ['team_id' => $team->id, 'user_ids' => $member_ids],
        );

        return response()->json([
            'message' => 'Members added successfully.',
            'team' => new AccountTeamResource($team),
        ]);
    }

    /**
     * DELETE /api/account-teams/{team}/members/{user}
     *
     * Removes a single member from the team's roster. Backs the roster
     * panel's per-row remove action. Team owners can remove regular members but only an admin
     * can remove another owner, so an owner cannot lock out the person who delegated to them.
     */
    public function destroy(Request $request, AccountTeam $team, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($team->canBeManagedBy($actor), 403, 'You cannot manage this team.');

        $is_target_owner = $team->owners()->whereKey($user->id)->exists();
        abort_if(
            $is_target_owner && ! $actor->hasRole(['super_admin', 'admin']),
            403,
            'Only an admin can remove a team owner.'
        );

        $team->members()->detach($user->id);
        $team->loadCount('members')->load('owners');

        AuditLogger::log(
            'team.member_removed',
            "Removed {$user->full_name} from team \"{$team->name}\".",
            $actor,
            ['team_id' => $team->id, 'user_id' => $user->id],
        );

        return response()->json([
            'message' => 'Member removed successfully.',
            'team' => new AccountTeamResource($team),
        ]);
    }

    /**
     * PUT /api/account-teams/{team}/owners/{user}
     *
     * Makes a staff user an owner of the team, adding them to the roster first when they are
     * not on it yet. Admin only, the route sits behind the admin role gate.
     */
    public function assignOwner(Request $request, AccountTeam $team, User $user): JsonResponse
    {
        abort_unless($user->hasRole(self::staffRoles()), 422, 'Only staff users can own a team.');

        $team->members()->syncWithoutDetaching([$user->id => ['is_team_owner' => true]]);
        $team->loadCount('members')->load('owners');

        AuditLogger::log(
            'team.owner_assigned',
            "Made {$user->full_name} an owner of team \"{$team->name}\".",
            $request->user(),
            ['team_id' => $team->id, 'user_id' => $user->id],
        );

        return response()->json([
            'message' => 'Team owner assigned successfully.',
            'team' => new AccountTeamResource($team),
        ]);
    }

    /**
     * DELETE /api/account-teams/{team}/owners/{user}
     *
     * Takes the owner delegation away but keeps the user on the roster. Admin only.
     */
    public function removeOwner(Request $request, AccountTeam $team, User $user): JsonResponse
    {
        $team->members()->updateExistingPivot($user->id, ['is_team_owner' => false]);
        $team->loadCount('members')->load('owners');

        AuditLogger::log(
            'team.owner_removed',
            "Removed {$user->full_name} as an owner of team \"{$team->name}\".",
            $request->user(),
            ['team_id' => $team->id, 'user_id' => $user->id],
        );

        return response()->json([
            'message' => 'Team owner removed successfully.',
            'team' => new AccountTeamResource($team),
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
     * staff user regardless of existing team membership, unless
     * `exclude_team_id` is given (the "Add members" panel, which only wants
     * people who aren't already on that team).
     */
    public function candidates(Request $request): JsonResponse
    {
        $query = User::whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', self::staffRoles()))
            ->with('roles:id,name')
            ->orderBy('first_name')
            ->orderBy('last_name');

        if ($exclude_team_id = $request->query('exclude_team_id')) {
            $query->whereDoesntHave(
                'accountTeams',
                fn (Builder $teams) => $teams->where('account_teams.id', $exclude_team_id)
            );
        }

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
