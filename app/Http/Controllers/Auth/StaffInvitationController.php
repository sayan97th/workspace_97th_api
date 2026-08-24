<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\IssuesJwtTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptStaffInvitationRequest;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public (unauthenticated) endpoints for the emailed staff-invitation link, the invitee has
 * no account or session yet, so these sit alongside register/login rather than under the
 * `auth:api` group. Unlike {@see WorkspaceInvitationController}, a staff invitation always
 * creates a brand-new account, an email that already has one is rejected up front when the
 * invitation is sent.
 */
class StaffInvitationController extends Controller
{
    use IssuesJwtTokens;

    /**
     * GET /api/auth/staff-invitations/{invitation}
     */
    public function show(StaffInvitation $invitation): JsonResponse
    {
        $invitation->load('inviter');

        return response()->json([
            'email' => $invitation->email,
            'role' => $invitation->role,
            'inviter_name' => $invitation->inviter->full_name,
            'message' => $invitation->message,
            'status' => $invitation->isAccepted() ? 'accepted' : ($invitation->isExpired() ? 'expired' : 'pending'),
        ]);
    }

    /**
     * POST /api/auth/staff-invitations/{invitation}/accept
     *
     * Creates the invitee's account with the invited role (and department, if any), and
     * logs them in, mirroring `AuthController::register()`.
     */
    public function accept(AcceptStaffInvitationRequest $request, StaffInvitation $invitation): JsonResponse
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation is no longer valid.',
            ])->status(422);
        }

        $validated = $request->validated();

        $user = DB::transaction(function () use ($invitation, $validated) {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $invitation->email,
                'password' => $validated['password'],
                'department_id' => $invitation->department_id,
            ]);

            $user->assignRole($invitation->role);
            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        $token = $this->guard()->login($user);

        return $this->respondWithToken($token, $user);
    }
}
