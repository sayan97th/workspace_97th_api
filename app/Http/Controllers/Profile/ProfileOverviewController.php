<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\AccountTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read only account details shown on the My Profile header and Personal info page: the
 * department and the teams the signed in user belongs to. Kept out of {@see ProfileResource}
 * so the auth endpoints that return the profile do not load these relations on every call.
 */
class ProfileOverviewController extends Controller
{
    /**
     * GET /api/profile/overview
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load([
            'department:id,name',
            'accountTeams' => fn ($query) => $query->select('account_teams.id', 'account_teams.name', 'account_teams.picture_path')
                ->withCount('members')
                ->orderBy('account_teams.name'),
        ]);

        return response()->json([
            'data' => [
                'department' => $user->department ? [
                    'id' => $user->department->id,
                    'name' => $user->department->name,
                ] : null,
                'teams' => $user->accountTeams->map(fn (AccountTeam $team) => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'picture_url' => $team->picture_url,
                    'members_count' => $team->members_count,
                ])->values(),
            ],
        ]);
    }
}
