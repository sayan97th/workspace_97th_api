<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\AuthorizesWorkspaceManagement;
use App\Http\Requests\Workspace\StoreWorkspaceAvatarRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceAvatarController extends Controller
{
    use AuthorizesWorkspaceManagement;

    public function __construct(private readonly WorkspaceAvatarService $avatars) {}

    /**
     * POST /api/workspaces/{workspace}/avatar
     *
     * Uploads (or replaces) the workspace's custom avatar image, shown
     * instead of its generated mono/color badge. Owner or admin only.
     */
    public function store(StoreWorkspaceAvatarRequest $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeWorkspaceManagement($workspace, $user, 'change this workspace\'s avatar');

        $this->avatars->store($workspace, $request->file('file'));
        $this->setMembershipAttributes($workspace, $user->id);

        return response()->json([
            'message' => 'Workspace avatar uploaded successfully.',
            'workspace' => new WorkspaceResource($workspace),
        ]);
    }

    /**
     * DELETE /api/workspaces/{workspace}/avatar
     *
     * Removes the workspace's custom avatar, reverting it to its generated
     * mono/color badge. Owner or admin only.
     */
    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeWorkspaceManagement($workspace, $user, 'remove this workspace\'s avatar');

        $this->avatars->destroy($workspace);
        $this->setMembershipAttributes($workspace, $user->id);

        return response()->json([
            'message' => 'Workspace avatar removed successfully.',
            'workspace' => new WorkspaceResource($workspace),
        ]);
    }

    /**
     * {@see WorkspaceResource} reads the current user's role/recency off
     * transient attributes the controller is expected to populate (they
     * aren't real columns) — see {@see WorkspaceController::update()} for the
     * same convention.
     */
    private function setMembershipAttributes(Workspace $workspace, int $user_id): void
    {
        $membership = $this->membershipFor($workspace, $user_id);
        $workspace->setAttribute('membership_role', $membership->role ?? null);
        $workspace->setAttribute('membership_is_recent', (bool) ($membership->is_recent ?? false));
    }
}
