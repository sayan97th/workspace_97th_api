<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\StoreWorkspaceInvitationRequest;
use App\Http\Resources\WorkspaceInvitationResource;
use App\Jobs\SendEmailJob;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceInvitationController extends Controller
{
    /**
     * POST /api/workspaces/{workspace}/invitations
     *
     * Bulk-invites one or more email addresses to join the workspace with a
     * single role, resending (rather than duplicating) any invitation still
     * pending for an address. Restricted to owners.
     */
    public function store(StoreWorkspaceInvitationRequest $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $membership = $this->membershipFor($workspace, $user->id);
        if (($membership->role ?? null) !== 'owner') {
            throw ValidationException::withMessages([
                'workspace' => 'Only the workspace owner can invite members.',
            ])->status(403);
        }

        $validated = $request->validated();
        $role = (string) $validated['role'];
        $message = $validated['message'] ?? null;

        /** @var array<int, string> $submitted_emails */
        $submitted_emails = $validated['emails'];
        $emails = collect($submitted_emails)->unique(fn (string $email) => strtolower($email));

        $existing_member_emails = $workspace->users()
            ->whereIn('email', $emails)
            ->pluck('email')
            ->map(fn (string $email) => strtolower($email));

        $invitations = collect();
        $skipped = collect();

        foreach ($emails as $index => $email) {
            if ($existing_member_emails->contains(strtolower($email))) {
                $skipped->push(['email' => $email, 'reason' => 'already_member']);

                continue;
            }

            $invitation = WorkspaceInvitation::where('workspace_id', $workspace->id)
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->whereNull('accepted_at')
                ->first();

            $invitation = DB::transaction(function () use ($workspace, $invitation, $email, $role, $message, $user) {
                $attributes = [
                    'role' => $role,
                    'message' => $message,
                    'invited_by' => $user->id,
                    'expires_at' => now()->addDays(7),
                ];

                if ($invitation) {
                    $invitation->update($attributes);

                    return $invitation;
                }

                return $workspace->invitations()->create($attributes + ['email' => $email]);
            });

            SendEmailJob::dispatchWithThrottle(new WorkspaceInvitationMail($invitation), $invitation->email, $index);
            $invitations->push($invitation);
        }

        return response()->json([
            'message' => $invitations->isEmpty()
                ? 'No new invitations were sent.'
                : 'Invitations sent successfully.',
            'data' => WorkspaceInvitationResource::collection($invitations),
            'skipped' => $skipped->values(),
        ], 201);
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
