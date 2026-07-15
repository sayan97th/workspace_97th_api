<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
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
        ]);

        $workspace->users()->attach($user->id, ['role' => 'owner', 'is_recent' => true]);

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
