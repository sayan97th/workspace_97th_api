<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\AuthorizesWorkspaceManagement;
use App\Http\Requests\Workspace\UpdateWorkspaceMemberRoleRequest;
use App\Http\Resources\WorkspaceMemberResource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manages an existing member's standing within a workspace — changing their
 * role or removing them outright. Adding a member stays the invitation
 * flow's job ({@see WorkspaceInvitationController}); this controller only
 * ever acts on someone already in {@see Workspace::users()}.
 */
class WorkspaceMemberController extends Controller
{
    use AuthorizesWorkspaceManagement;

    /**
     * PATCH /api/workspaces/{workspace}/members/{member}
     *
     * Changes a member's role. Restricted to the workspace's own owner or
     * privileged staff; a member can never change their own role here (that
     * goes through "Leave workspace" or "Transfer ownership" instead, both of
     * which handle what happens to the acting user explicitly), and the sole
     * remaining owner can't be demoted without first promoting someone else.
     */
    public function update(UpdateWorkspaceMemberRoleRequest $request, Workspace $workspace, User $member): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeWorkspaceManagement($workspace, $user, "change a member's role");

        $members = DB::table('workspace_user')->where('workspace_id', $workspace->id)->get();
        $membership = $members->firstWhere('user_id', $member->id);

        if (! $membership) {
            throw ValidationException::withMessages([
                'member' => 'That person is not a member of this workspace.',
            ])->status(404);
        }

        if ($member->id === $user->id) {
            throw ValidationException::withMessages([
                'member' => "You can't change your own role. Use \"Transfer ownership\" or \"Leave workspace\" instead.",
            ])->status(422);
        }

        $new_role = (string) $request->validated('role');

        $other_owner_exists = $members->contains(
            fn ($row) => $row->user_id !== $member->id && $row->role === 'owner'
        );

        if ($membership->role === 'owner' && $new_role !== 'owner' && ! $other_owner_exists) {
            throw ValidationException::withMessages([
                'member' => 'Assign another owner before changing this member\'s role.',
            ])->status(422);
        }

        $workspace->users()->updateExistingPivot($member->id, ['role' => $new_role]);

        $updated_member = $workspace->users()->whereKey($member->id)->firstOrFail();
        $updated_member->setAttribute('is_workspace_creator', $workspace->isCreator($member->id));

        return response()->json([
            'message' => 'Member role updated successfully.',
            'data' => new WorkspaceMemberResource($updated_member),
        ]);
    }

    /**
     * DELETE /api/workspaces/{workspace}/members/{member}
     *
     * Removes a member from the workspace. Restricted to the workspace's own
     * owner or privileged staff. Three people can never be removed this way:
     * the acting user themselves (use "Leave workspace"), the workspace's
     * original creator (permanent, see {@see Workspace::isCreator()}), and
     * the sole remaining owner (assign another owner first) — the same
     * invariant {@see WorkspaceController::leave()} protects.
     */
    public function destroy(Request $request, Workspace $workspace, User $member): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeWorkspaceManagement($workspace, $user, 'remove members');

        $members = DB::table('workspace_user')->where('workspace_id', $workspace->id)->get();
        $membership = $members->firstWhere('user_id', $member->id);

        if (! $membership) {
            throw ValidationException::withMessages([
                'member' => 'That person is not a member of this workspace.',
            ])->status(404);
        }

        if ($member->id === $user->id) {
            throw ValidationException::withMessages([
                'member' => 'Use "Leave workspace" to remove yourself.',
            ])->status(422);
        }

        if ($workspace->isCreator($member->id)) {
            throw ValidationException::withMessages([
                'member' => 'The workspace creator can\'t be removed.',
            ])->status(403);
        }

        $other_owner_exists = $members->contains(
            fn ($row) => $row->user_id !== $member->id && $row->role === 'owner'
        );

        if ($membership->role === 'owner' && ! $other_owner_exists) {
            throw ValidationException::withMessages([
                'member' => 'Assign another owner before removing this member.',
            ])->status(422);
        }

        $workspace->users()->detach($member->id);

        return response()->json([
            'message' => 'Member removed from the workspace.',
        ]);
    }
}
