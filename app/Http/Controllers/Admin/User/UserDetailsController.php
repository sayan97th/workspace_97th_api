<?php

namespace App\Http\Controllers\Admin\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserSessionResource;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\UserWithRolesResource;
use App\Models\AuditLog;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\UserSession;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/users/{user}/details
 *
 * Everything the Administration > Users details drawer shows for one person in a single
 * round trip: the profile, teams and workspaces, owned boards, active sessions, a few
 * activity counts and the latest audit events by or about them. Works for deleted users too
 * (the route binds `withTrashed()`) so an admin can review an account before restoring it.
 */
class UserDetailsController extends Controller
{
    private const RECENT_AUDIT_LIMIT = 15;

    private const OWNED_BOARDS_LIMIT = 20;

    public function __invoke(User $user): JsonResponse
    {
        $user->load(['roles:id,name,display_name', 'department:id,name', 'profileFieldValues']);
        $user->setAttribute('last_active_at', $user->sessions()->max('last_used_at'));

        $owned_boards_query = WorkspaceNavigationItem::query()->boards()->where('owner_id', $user->id);

        $owned_boards = (clone $owned_boards_query)
            ->with('workspace:id,name')
            ->orderByDesc('updated_at')
            ->limit(self::OWNED_BOARDS_LIMIT)
            ->get();

        $sessions = UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>=', now())
            ->orderByDesc('last_used_at')
            ->get();

        $recent_audit = AuditLog::with('actor:id,first_name,last_name')
            ->where(function (Builder $query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('metadata->target_user_id', $user->id);
            })
            ->orderByDesc('created_at')
            ->limit(self::RECENT_AUDIT_LIMIT)
            ->get();

        return response()->json([
            'user' => new UserWithRolesResource($user),
            'teams' => $user->accountTeams()
                ->withPivot('is_team_owner')
                ->orderBy('name')
                ->get(['account_teams.id', 'account_teams.name'])
                ->map(fn ($team) => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'is_team_owner' => (bool) $team->pivot->is_team_owner,
                ])->values(),
            'workspaces' => $user->workspaces()
                ->orderBy('name')
                ->get(['workspaces.id', 'workspaces.name'])
                ->map(fn ($workspace) => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'role' => $workspace->pivot->role,
                ])->values(),
            'owned_boards' => [
                'total' => (clone $owned_boards_query)->count(),
                'data' => $owned_boards->map(fn (WorkspaceNavigationItem $board) => [
                    'id' => $board->id,
                    'label' => $board->label,
                    'workspace' => $board->workspace ? ['id' => $board->workspace->id, 'name' => $board->workspace->name] : null,
                    'is_archived' => $board->is_archived,
                ])->values(),
            ],
            'sessions' => AdminUserSessionResource::collection($sessions),
            'stats' => [
                'items_created' => BoardItem::query()->where('created_by_id', $user->id)->count(),
                'updates_posted' => BoardItemComment::query()->where('user_id', $user->id)->count(),
                'boards_owned' => $owned_boards_query->count(),
            ],
            'recent_activity' => AuditLogResource::collection($recent_audit),
        ]);
    }
}
