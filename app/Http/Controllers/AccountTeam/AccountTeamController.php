<?php

namespace App\Http\Controllers\AccountTeam;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountTeam\StoreAccountTeamRequest;
use App\Http\Requests\AccountTeam\UpdateAccountTeamRequest;
use App\Http\Resources\AccountTeamResource;
use App\Models\AccountTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountTeamController extends Controller
{
    /**
     * GET /api/account-teams
     *
     * Every team in the account, for the Teams rail. Small, unpaginated list
     * (the number of *teams* an org creates stays modest even when its
     * headcount doesn't) — the roster inside each team is what needs paging.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');

        $query = AccountTeam::withCount('members')->orderBy('name');

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
     * Creates a team and, when `member_ids` is given, seeds its roster in the
     * same transaction so the rail never briefly shows an empty team.
     */
    public function store(StoreAccountTeamRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $team = DB::transaction(function () use ($validated, $request) {
            $team = AccountTeam::create([
                'name' => $validated['name'],
                'created_by_id' => $request->user()->id,
            ]);

            if (! empty($validated['member_ids'])) {
                $team->members()->sync($validated['member_ids']);
            }

            return $team;
        });

        $team->loadCount('members');

        return response()->json([
            'message' => 'Team created successfully.',
            'team' => new AccountTeamResource($team),
        ], 201);
    }

    /**
     * GET /api/account-teams/{team}
     */
    public function show(AccountTeam $team): JsonResponse
    {
        $team->loadCount('members');

        return response()->json(new AccountTeamResource($team));
    }

    /**
     * PATCH /api/account-teams/{team}
     */
    public function update(UpdateAccountTeamRequest $request, AccountTeam $team): JsonResponse
    {
        $team->update($request->validated());
        $team->loadCount('members');

        return response()->json([
            'message' => 'Team updated successfully.',
            'team' => new AccountTeamResource($team),
        ]);
    }

    /**
     * DELETE /api/account-teams/{team}
     *
     * Soft-deletes the team. Its roster pivot rows are left in place (the
     * team is gone from every query the moment it's trashed, so they're
     * inert) rather than detached, so restoring a trashed team is a future
     * one-line fix instead of a data-recovery problem.
     */
    public function destroy(AccountTeam $team): JsonResponse
    {
        $team->delete();

        return response()->json([
            'message' => 'Team deleted successfully.',
        ]);
    }
}
