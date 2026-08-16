<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\UpdateWorkspaceInviteLinkRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspacePermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Manages each workspace's single reusable "invite with link" share link, as
 * opposed to {@see WorkspaceInvitationController}'s per-email invitations:
 * anyone holding the link can join without being addressed by name, so it's
 * modeled as one mutable resource per workspace rather than a collection.
 * Gated the same way as email invitations (owner or privileged staff).
 */
class WorkspaceInviteLinkController extends Controller
{
    private const PRIVILEGED_GLOBAL_ROLES = ['super_admin', 'admin'];

    /**
     * GET /api/workspaces/{workspace}/invite-link
     */
    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeLinkManagement($workspace, $user);

        return response()->json($this->present($workspace));
    }

    /**
     * PATCH /api/workspaces/{workspace}/invite-link
     *
     * Turns link invites on/off and/or changes the role granted to whoever
     * joins through it.
     */
    public function update(UpdateWorkspaceInviteLinkRequest $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeLinkManagement($workspace, $user);

        $validated = $request->validated();

        $workspace->update([
            'invite_enabled' => $validated['enabled'] ?? $workspace->invite_enabled,
            'invite_role' => $validated['role'] ?? $workspace->invite_role,
        ]);

        return response()->json($this->present($workspace));
    }

    /**
     * POST /api/workspaces/{workspace}/invite-link/regenerate
     *
     * Rotates the code so a previously shared link stops working, e.g. after
     * it leaked outside the intended audience.
     */
    public function regenerate(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeLinkManagement($workspace, $user);

        $workspace->update([
            'invite_code' => Str::random(48),
            'invite_generated_by' => $user->id,
        ]);

        return response()->json($this->present($workspace));
    }

    /**
     * Response shape shared by all three endpoints above.
     *
     * @return array<string, mixed>
     */
    private function present(Workspace $workspace): array
    {
        $frontend_url = rtrim((string) config('app.frontend_url'), '/');

        return [
            'url' => "{$frontend_url}/join/{$workspace->invite_code}",
            'role' => $workspace->invite_role,
            'role_label' => WorkspacePermissionCatalog::labelFor($workspace->invite_role),
            'enabled' => $workspace->invite_enabled,
        ];
    }

    /**
     * Look up the current user's membership row for a workspace.
     */
    private function membershipFor(Workspace $workspace, int $user_id): ?object
    {
        return DB::table('workspace_user')
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user_id)
            ->first();
    }

    /**
     * Same gate as email invitations: the workspace's own owner, or a user
     * holding a {@see PRIVILEGED_GLOBAL_ROLES} role.
     */
    private function authorizeLinkManagement(Workspace $workspace, User $user): void
    {
        if ($user->hasRole(self::PRIVILEGED_GLOBAL_ROLES)) {
            return;
        }

        $membership = $this->membershipFor($workspace, $user->id);
        if (($membership->role ?? null) === 'owner') {
            return;
        }

        throw ValidationException::withMessages([
            'workspace' => 'Only the workspace owner or an administrator can manage the invite link.',
        ])->status(403);
    }
}
