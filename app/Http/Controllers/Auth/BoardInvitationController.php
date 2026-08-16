<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\IssuesJwtTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptBoardInvitationRequest;
use App\Models\BoardInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Public (unauthenticated) endpoints for the emailed board-invitation link,
 * the invitee may not have an account or a session yet, so these sit
 * alongside register/login rather than under the `auth:api` group. Mirrors
 * {@link WorkspaceInvitationController}, scoped to a single board instead of
 * a whole workspace.
 */
class BoardInvitationController extends Controller
{
    use IssuesJwtTokens;

    /**
     * GET /api/auth/board-invitations/{invitation}
     *
     * Preview an invitation before the invitee decides to accept/decline.
     */
    public function show(BoardInvitation $invitation): JsonResponse
    {
        $invitation->load(['board.workspace', 'inviter']);

        return response()->json([
            'email' => $invitation->email,
            'board' => [
                'id' => $invitation->board->id,
                'label' => $invitation->board->label,
                'icon' => $invitation->board->icon,
            ],
            'workspace' => [
                'id' => $invitation->board->workspace->id,
                'name' => $invitation->board->workspace->name,
                'mono' => $invitation->board->workspace->mono,
                'color' => $invitation->board->workspace->color,
            ],
            'inviter_name' => $invitation->inviter->full_name,
            'message' => $invitation->message,
            'status' => $invitation->isAccepted() ? 'accepted' : ($invitation->isExpired() ? 'expired' : 'pending'),
            'account_exists' => User::where('email', $invitation->email)->exists(),
        ]);
    }

    /**
     * POST /api/auth/board-invitations/{invitation}/accept
     *
     * Creates the invitee's account when needed (or authenticates their
     * existing one), grants them view access to the board, joins them to its
     * workspace as a viewer if they weren't already a member (never
     * downgrading an existing membership), and logs them in.
     */
    public function accept(AcceptBoardInvitationRequest $request, BoardInvitation $invitation): JsonResponse
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
            $invitation->board->collaborators()->syncWithoutDetaching([
                $user->id => ['invited_by' => $invitation->invited_by],
            ]);

            $already_a_member = DB::table('workspace_user')
                ->where('workspace_id', $invitation->board->workspace_id)
                ->where('user_id', $user->id)
                ->exists();

            if (! $already_a_member) {
                $invitation->board->workspace->users()->attach($user->id, [
                    'role' => 'viewer',
                    'is_recent' => true,
                    'invited_by' => $invitation->invited_by,
                ]);
            }

            $invitation->update(['accepted_at' => now()]);
        });

        $token = $this->guard()->login($user);

        return $this->respondWithToken($token, $user);
    }

    /**
     * POST /api/auth/board-invitations/{invitation}/decline
     */
    public function decline(BoardInvitation $invitation): JsonResponse
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
