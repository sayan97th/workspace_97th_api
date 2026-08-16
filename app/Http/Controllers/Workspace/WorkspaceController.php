<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Workspace\TransferWorkspaceOwnershipRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceRequest;
use App\Http\Resources\WorkspaceMemberResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    /**
     * GET /api/workspaces
     *
     * Returns every workspace (for the "Browse all" catalog) annotated with the
     * current user's membership so the switcher can build its recent/mine lists.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $memberships = DB::table('workspace_user')
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('workspace_id');

        $workspaces = Workspace::orderByDesc('is_home')
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->each(function (Workspace $workspace) use ($memberships) {
                $membership = $memberships->get($workspace->id);
                $workspace->setAttribute('membership_role', $membership->role ?? null);
                $workspace->setAttribute('membership_is_recent', (bool) ($membership->is_recent ?? false));
            });

        return response()->json([
            'data' => WorkspaceResource::collection($workspaces),
        ]);
    }

    /**
     * GET /api/workspaces/{workspace}
     */
    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $membership = DB::table('workspace_user')
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->first();

        $workspace->setAttribute('membership_role', $membership->role ?? null);
        $workspace->setAttribute('membership_is_recent', (bool) ($membership->is_recent ?? false));

        return response()->json(new WorkspaceResource($workspace));
    }

    /**
     * POST /api/workspaces
     *
     * Creates a workspace and enrolls the creator as its owner.
     */
    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();
        $name = (string) $validated['name'];

        $workspace = Workspace::create([
            'name' => $name,
            'mono' => $validated['mono'] ?? Str::upper(Str::substr($name, 0, 1)) ?: 'W',
            'color' => $validated['color'] ?? '#e53e2e',
            'product' => $validated['product'] ?? 'Workspace 97th',
            'privacy' => $validated['privacy'] ?? 'open',
            'description' => $validated['description'] ?? null,
            'invite_generated_by' => $user->id,
        ]);

        $workspace->users()->attach($user->id, ['role' => 'owner', 'is_recent' => true]);

        // Every workspace gets a "Manage Workspace" entry point for free, the
        // same way the seeded workspaces do (see WorkspaceSeeder).
        $workspace->navigationItems()->create([
            'parent_id' => null,
            'type' => WorkspaceNavigationItem::TYPE_LEAF,
            'label' => 'Manage Workspace',
            'slug' => 'manage-workspace',
            'icon' => 'home',
            'view_key' => 'workspace_manage',
            'is_favorite' => false,
            'position' => 0,
            'created_by_id' => $user->id,
        ]);

        $workspace->setAttribute('membership_role', 'owner');
        $workspace->setAttribute('membership_is_recent', true);

        return response()->json([
            'message' => 'Workspace created successfully.',
            'workspace' => new WorkspaceResource($workspace),
        ], 201);
    }

    /**
     * PATCH /api/workspaces/{workspace}
     *
     * Renames the workspace and/or updates its appearance/type. Restricted to owners.
     */
    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $membership = $this->membershipFor($workspace, $user->id);
        if (($membership->role ?? null) !== 'owner') {
            throw ValidationException::withMessages([
                'workspace' => 'Only the workspace owner can update this workspace.',
            ])->status(403);
        }

        $workspace->update($request->validated());

        $workspace->setAttribute('membership_role', $membership->role);
        $workspace->setAttribute('membership_is_recent', (bool) $membership->is_recent);

        return response()->json([
            'message' => 'Workspace updated successfully.',
            'workspace' => new WorkspaceResource($workspace),
        ]);
    }

    /**
     * DELETE /api/workspaces/{workspace}
     *
     * Soft-deletes the workspace. Restricted to owners.
     */
    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $membership = $this->membershipFor($workspace, $user->id);
        if (($membership->role ?? null) !== 'owner') {
            throw ValidationException::withMessages([
                'workspace' => 'Only the workspace owner can delete this workspace.',
            ])->status(403);
        }

        $workspace->delete();

        return response()->json([
            'message' => 'Workspace deleted successfully.',
        ]);
    }

    /**
     * POST /api/workspaces/{workspace}/leave
     *
     * Removes the current user from the workspace. Blocked when they're the sole
     * member (delete the workspace instead) or the sole owner (assign another
     * owner first) so a workspace never ends up without an owner.
     */
    public function leave(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $members = DB::table('workspace_user')
            ->where('workspace_id', $workspace->id)
            ->get();

        $membership = $members->firstWhere('user_id', $user->id);
        if (! $membership) {
            throw ValidationException::withMessages([
                'workspace' => 'You are not a member of this workspace.',
            ])->status(403);
        }

        if ($members->count() === 1) {
            throw ValidationException::withMessages([
                'workspace' => 'You are the only member of this workspace. Delete it instead of leaving.',
            ])->status(422);
        }

        $other_owner_exists = $members->contains(
            fn ($member) => $member->user_id !== $user->id && $member->role === 'owner'
        );

        if ($membership->role === 'owner' && ! $other_owner_exists) {
            throw ValidationException::withMessages([
                'workspace' => 'Assign another owner before leaving this workspace.',
            ])->status(422);
        }

        $workspace->users()->detach($user->id);

        return response()->json([
            'message' => 'You have left the workspace.',
        ]);
    }

    /**
     * POST /api/workspaces/{workspace}/transfer-ownership
     *
     * Hands the "owner" role to another member in one atomic step, together
     * with what happens to the current owner: stay on with a new role, or
     * leave the workspace entirely. Restricted to the current owner.
     */
    public function transferOwnership(TransferWorkspaceOwnershipRequest $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $members = DB::table('workspace_user')
            ->where('workspace_id', $workspace->id)
            ->get();

        $membership = $members->firstWhere('user_id', $user->id);
        if (($membership->role ?? null) !== 'owner') {
            throw ValidationException::withMessages([
                'workspace' => 'Only the workspace owner can transfer ownership.',
            ])->status(403);
        }

        $validated = $request->validated();
        $new_owner_id = (int) $validated['new_owner_id'];
        $self_role = (string) $validated['self_role'];

        if ($new_owner_id === $user->id) {
            throw ValidationException::withMessages([
                'new_owner_id' => 'Choose someone else to become the new owner.',
            ])->status(422);
        }

        if (! $members->contains('user_id', $new_owner_id)) {
            throw ValidationException::withMessages([
                'new_owner_id' => 'That person is not a member of this workspace.',
            ])->status(422);
        }

        $leaves = $self_role === 'leave';

        DB::transaction(function () use ($workspace, $user, $new_owner_id, $self_role, $leaves) {
            $workspace->users()->updateExistingPivot($new_owner_id, ['role' => 'owner']);

            if ($leaves) {
                $workspace->users()->detach($user->id);
            } else {
                $workspace->users()->updateExistingPivot($user->id, ['role' => $self_role]);
            }
        });

        return response()->json([
            'message' => $leaves
                ? 'Ownership transferred. You have left the workspace.'
                : 'Ownership transferred.',
            'left' => $leaves,
        ]);
    }

    /**
     * GET /api/workspaces/{workspace}/members
     *
     * The full member roster (with role) for the Manage Workspace
     * "Collaborations" tab.
     */
    public function members(Workspace $workspace): JsonResponse
    {
        $members = $workspace->users()->orderBy('first_name')->orderBy('last_name')->get();

        return response()->json([
            'data' => WorkspaceMemberResource::collection($members),
        ]);
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
}
