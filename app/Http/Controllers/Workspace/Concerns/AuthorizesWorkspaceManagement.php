<?php

namespace App\Http\Controllers\Workspace\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspacePermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared membership lookup + authorization gate for every controller that
 * manages a workspace's roster (invitations, members): allowed for the
 * workspace's own owner, or for a user holding one of
 * {@see WorkspacePermissionCatalog::privilegedGlobalRoles()} (staff who
 * manage workspaces they don't personally belong to).
 */
trait AuthorizesWorkspaceManagement
{
    /**
     * Look up a user's membership row (role / is_recent / invited_by) for a
     * workspace, or null when they aren't a member.
     */
    private function membershipFor(Workspace $workspace, int $user_id): ?object
    {
        return DB::table('workspace_user')
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user_id)
            ->first();
    }

    /**
     * @throws ValidationException when the user may not manage this workspace's roster.
     */
    private function authorizeWorkspaceManagement(Workspace $workspace, User $user, string $action): void
    {
        if ($user->hasRole(WorkspacePermissionCatalog::privilegedGlobalRoles())) {
            return;
        }

        $membership = $this->membershipFor($workspace, $user->id);
        if (($membership->role ?? null) === 'owner') {
            return;
        }

        throw ValidationException::withMessages([
            'workspace' => "Only the workspace owner or an administrator can {$action}.",
        ])->status(403);
    }
}
