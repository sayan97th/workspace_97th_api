<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\IssuesJwtTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\JoinWorkspaceByLinkRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspacePermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Public (unauthenticated) endpoints for a workspace's shareable "invite
 * with link" URL. Mirrors {@see WorkspaceInvitationController} (the emailed,
 * per-address flow), but the joiner isn't known in advance, so they identify
 * themselves with an email in the accept step instead of being looked up by
 * invitation code alone.
 */
class WorkspaceInviteLinkController extends Controller
{
    use IssuesJwtTokens;

    /**
     * GET /api/auth/workspaces/join/{invite_code}
     *
     * Preview a workspace before deciding to join, and tell the frontend
     * whether link invites are currently turned off.
     */
    public function show(string $invite_code): JsonResponse
    {
        $workspace = Workspace::where('invite_code', $invite_code)->firstOrFail();

        return response()->json([
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'mono' => $workspace->mono,
                'color' => $workspace->color,
            ],
            'role' => $workspace->invite_role,
            'role_label' => WorkspacePermissionCatalog::labelFor($workspace->invite_role),
            'enabled' => $workspace->invite_enabled,
        ]);
    }

    /**
     * POST /api/auth/workspaces/join/{invite_code}
     *
     * Creates the joiner's account when needed (or authenticates their
     * existing one), attaches them to the workspace with the link's role,
     * and logs them in.
     */
    public function accept(JoinWorkspaceByLinkRequest $request, string $invite_code): JsonResponse
    {
        $workspace = Workspace::where('invite_code', $invite_code)->firstOrFail();

        if (! $workspace->invite_enabled) {
            throw ValidationException::withMessages([
                'invite_code' => 'This invite link has been turned off by the workspace owner.',
            ])->status(422);
        }

        $validated = $request->validated();
        $existing_user = User::where('email', $validated['email'])->first();

        if ($existing_user) {
            if (! Hash::check($validated['password'], $existing_user->password)) {
                throw ValidationException::withMessages([
                    'password' => 'The provided password is incorrect.',
                ]);
            }

            $user = $existing_user;
        } else {
            $user = DB::transaction(function () use ($validated) {
                $user = User::create([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                ]);

                $user->assignRole('client');

                return $user;
            });
        }

        $workspace->users()->syncWithoutDetaching([
            $user->id => [
                'role' => $workspace->invite_role,
                'is_recent' => true,
                'invited_by' => $workspace->invite_generated_by,
            ],
        ]);

        $token = $this->guard()->login($user);

        return $this->respondWithToken($token, $user);
    }
}
