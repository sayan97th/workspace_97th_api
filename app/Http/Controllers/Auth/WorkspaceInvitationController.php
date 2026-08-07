<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\IssuesJwtTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptWorkspaceInvitationRequest;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Support\WorkspacePermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Public (unauthenticated) endpoints for the emailed workspace-invitation
 * link — the invitee may not have an account or a session yet, so these sit
 * alongside register/login rather than under the `auth:api` group.
 */
class WorkspaceInvitationController extends Controller
{
    use IssuesJwtTokens;

    /**
     * GET /api/auth/invitations/{invitation}
     *
     * Preview an invitation before the invitee decides to accept/decline —
     * tells the frontend whether to render a "set your password" form (new
     * account) or a "confirm your password" form (existing account), and
     * whether the invitation is still actionable at all.
     */
    public function show(WorkspaceInvitation $invitation): JsonResponse
    {
        $invitation->load(['workspace', 'inviter']);

        return response()->json([
            'email' => $invitation->email,
            'role' => $invitation->role,
            'role_label' => WorkspacePermissionCatalog::labelFor($invitation->role),
            'workspace' => [
                'id' => $invitation->workspace->id,
                'name' => $invitation->workspace->name,
                'mono' => $invitation->workspace->mono,
                'color' => $invitation->workspace->color,
            ],
            'inviter_name' => $invitation->inviter->full_name,
            'status' => $invitation->isAccepted() ? 'accepted' : ($invitation->isExpired() ? 'expired' : 'pending'),
            'account_exists' => User::where('email', $invitation->email)->exists(),
        ]);
    }

    /**
     * POST /api/auth/invitations/{invitation}/accept
     *
     * Creates the invitee's account when needed (or authenticates their
     * existing one), attaches them to the workspace with the invited role,
     * and logs them in — mirroring AuthController::register()/login().
     */
    public function accept(AcceptWorkspaceInvitationRequest $request, WorkspaceInvitation $invitation): JsonResponse
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation is no longer valid.',
            ])->status(422);
        }

        $validated = $request->validated();
        $existing_user = User::where('email', $invitation->email)->first();

        if ($existing_user) {
            if (! Hash::check($validated['password'], $existing_user->password)) {
                throw ValidationException::withMessages([
                    'password' => 'The provided password is incorrect.',
                ]);
            }

            $user = $existing_user;
        } else {
            $user = DB::transaction(function () use ($invitation, $validated) {
                $user = User::create([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'email' => $invitation->email,
                    'password' => $validated['password'],
                ]);

                $user->assignRole('client');

                return $user;
            });
        }

        DB::transaction(function () use ($invitation, $user) {
            $invitation->workspace->users()->syncWithoutDetaching([
                $user->id => ['role' => $invitation->role, 'is_recent' => true],
            ]);

            $invitation->update(['accepted_at' => now()]);
        });

        $token = $this->guard()->login($user);

        return $this->respondWithToken($token, $user);
    }

    /**
     * POST /api/auth/invitations/{invitation}/decline
     */
    public function decline(WorkspaceInvitation $invitation): JsonResponse
    {
        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation has already been accepted.',
            ])->status(422);
        }

        $invitation->delete();

        return response()->json([
            'message' => 'Invitation declined.',
        ]);
    }
}
