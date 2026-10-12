<?php

namespace App\Http\Controllers\AccountTeam;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountTeam\StoreAccountTeamRequest;
use App\Http\Requests\AccountTeam\UpdateAccountTeamRequest;
use App\Http\Resources\AccountTeamResource;
use App\Models\AccountTeam;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountTeamController extends Controller
{
    /**
     * GET /api/account-teams
     *
     * Every team in the account, for the Teams rail. Small, unpaginated list
     * (the number of *teams* an org creates stays modest even when its
     * headcount doesn't), the roster inside each team is what needs paging.
     * Sub-teams come back in the same flat list with their `parent_id`.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');

        $query = AccountTeam::withResourceData($request->user())->orderBy('name');

        if ($search !== null && $search !== '') {
            $query->where('name', 'LIKE', '%'.$search.'%');
        }

        return response()->json([
            'data' => AccountTeamResource::collection($query->get()),
        ]);
    }

    /**
     * POST /api/account-teams
     *
     * Creates a team (a sub-team when `parent_id` is given) and, when `member_ids` is given,
     * seeds its roster in the same transaction so the rail never briefly shows an empty team.
     */
    public function store(StoreAccountTeamRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $actor = $request->user();

        $team = DB::transaction(function () use ($validated, $actor) {
            $team = AccountTeam::create([
                'name' => $validated['name'],
                'parent_id' => $validated['parent_id'] ?? null,
                'allow_guests' => (bool) ($validated['allow_guests'] ?? false),
                'created_by_id' => $actor->id,
            ]);

            if (! empty($validated['member_ids'])) {
                $team->members()->sync($validated['member_ids']);
            }

            return $team;
        });

        AuditLogger::log(
            'team.created',
            "Created team \"{$team->name}\".",
            $actor,
            ['team_id' => $team->id, 'parent_id' => $team->parent_id],
        );

        return response()->json([
            'message' => 'Team created successfully.',
            'team' => new AccountTeamResource($team->loadResourceData($actor)),
        ], 201);
    }

    /**
     * GET /api/account-teams/{team}
     */
    public function show(Request $request, AccountTeam $team): JsonResponse
    {
        return response()->json(new AccountTeamResource($team->loadResourceData($request->user())));
    }

    /**
     * PATCH /api/account-teams/{team}
     *
     * Renames the team, moves it under another team (or back to the top level with a null
     * `parent_id`) and turns guests on or off. Guests can only be turned off once none are left
     * on the roster, so the setting never contradicts who is on the team.
     */
    public function update(UpdateAccountTeamRequest $request, AccountTeam $team): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('parent_id', $validated) && $validated['parent_id'] !== null) {
            $parent_id = (int) $validated['parent_id'];
            if ($parent_id === $team->id || in_array($parent_id, $team->descendantIds(), true)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'A team cannot be moved under itself or one of its own sub-teams.',
                ]);
            }
        }

        if (array_key_exists('allow_guests', $validated) && ! $validated['allow_guests'] && $team->allow_guests) {
            $has_guests = $team->members()
                ->whereHas('roles', fn ($roles) => $roles->where('name', AccountTeam::GUEST_ROLE))
                ->whereDoesntHave('roles', fn ($roles) => $roles->whereIn('name', AccountTeam::STAFF_ROLES))
                ->exists();
            if ($has_guests) {
                throw ValidationException::withMessages([
                    'allow_guests' => 'Remove the guests from this team before turning guests off.',
                ]);
            }
        }

        $team->update($validated);

        AuditLogger::log(
            'team.updated',
            "Updated team \"{$team->name}\".",
            $request->user(),
            ['team_id' => $team->id, 'changes' => array_keys($validated)],
        );

        return response()->json([
            'message' => 'Team updated successfully.',
            'team' => new AccountTeamResource($team->loadResourceData($request->user())),
        ]);
    }

    /**
     * DELETE /api/account-teams/{team}
     *
     * Soft-deletes the team. Its sub-teams move up to the deleted team's parent so none of them
     * disappears with it. Its roster pivot rows are left in place (the team is gone from every
     * query the moment it's trashed, so they're inert) rather than detached, so restoring a
     * trashed team is a future one-line fix instead of a data-recovery problem.
     */
    public function destroy(Request $request, AccountTeam $team): JsonResponse
    {
        DB::transaction(function () use ($team) {
            $team->subTeams()->update(['parent_id' => $team->parent_id]);
            $team->delete();
        });

        AuditLogger::log(
            'team.deleted',
            "Deleted team \"{$team->name}\".",
            $request->user(),
            ['team_id' => $team->id],
        );

        return response()->json([
            'message' => 'Team deleted successfully.',
        ]);
    }
}
