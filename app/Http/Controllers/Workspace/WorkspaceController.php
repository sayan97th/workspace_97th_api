<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\StoreWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
}
