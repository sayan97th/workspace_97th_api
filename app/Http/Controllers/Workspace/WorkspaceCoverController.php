<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\AuthorizesWorkspaceManagement;
use App\Http\Requests\Workspace\StoreWorkspaceCoverRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceCoverPositionRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceCoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceCoverController extends Controller
{
    use AuthorizesWorkspaceManagement;

    public function __construct(private readonly WorkspaceCoverService $covers) {}

    /**
     * POST /api/workspaces/{workspace}/cover
     *
     * Uploads (or replaces) the banner image shown at the top of Manage
     * Workspace. Owner or admin only.
     */
    public function store(StoreWorkspaceCoverRequest $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeWorkspaceManagement($workspace, $user, 'change this workspace\'s cover image');

        $this->covers->store($workspace, $request->file('file'), (int) $request->input('cover_position_y', 50));
        $this->setMembershipAttributes($workspace, $user->id);

        return response()->json([
            'message' => 'Workspace cover uploaded successfully.',
            'workspace' => new WorkspaceResource($workspace),
        ]);
    }

    /**
     * PATCH /api/workspaces/{workspace}/cover
     *
     * Saves the cover's vertical focal point after the user drags it into
     * place. Owner or admin only.
     */
    public function update(UpdateWorkspaceCoverPositionRequest $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeWorkspaceManagement($workspace, $user, 'reposition this workspace\'s cover image');

        $this->covers->reposition($workspace, (int) $request->validated('cover_position_y'));
        $this->setMembershipAttributes($workspace, $user->id);

        return response()->json([
            'message' => 'Workspace cover repositioned successfully.',
            'workspace' => new WorkspaceResource($workspace),
        ]);
    }

    /**
     * DELETE /api/workspaces/{workspace}/cover
     *
     * Removes the custom cover, reverting the banner to its default image.
     * Owner or admin only.
     */
    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeWorkspaceManagement($workspace, $user, 'remove this workspace\'s cover image');

        $this->covers->destroy($workspace);
        $this->setMembershipAttributes($workspace, $user->id);

        return response()->json([
            'message' => 'Workspace cover removed successfully.',
            'workspace' => new WorkspaceResource($workspace),
        ]);
    }

    /**
     * {@see WorkspaceResource} reads the current user's role/recency off
     * transient attributes, see {@see WorkspaceAvatarController} for the
     * same convention.
     */
    private function setMembershipAttributes(Workspace $workspace, int $user_id): void
    {
        $membership = $this->membershipFor($workspace, $user_id);
        $workspace->setAttribute('membership_role', $membership->role ?? null);
        $workspace->setAttribute('membership_is_recent', (bool) ($membership->is_recent ?? false));
    }
}
