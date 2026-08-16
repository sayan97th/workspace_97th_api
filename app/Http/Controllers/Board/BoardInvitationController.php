<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardInvitationRequest;
use App\Jobs\SendEmailJob;
use App\Mail\BoardInvitationMail;
use App\Models\BoardInvitation;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manages who can view a single board by email, on top of whoever already
 * sees it through their workspace role. Distinct from
 * {@link \App\Http\Controllers\Workspace\WorkspaceInvitationController},
 * which grants access to an entire workspace.
 */
class BoardInvitationController extends Controller
{
    /**
     * Global roles that can manage invitations for any board, regardless of
     * their own membership in its workspace — mirrors the workspace
     * invitation controller's equivalent gate.
     */
    private const PRIVILEGED_GLOBAL_ROLES = ['super_admin', 'admin'];

    /**
     * GET /api/boards/{item}/invitations
     *
     * Everyone who already has access to the board (workspace owners, then
     * explicit board collaborators), followed by anyone with a still-pending
     * invitation. Powers the "Invite to this board" dialog's roster.
     */
    public function index(WorkspaceNavigationItem $item): JsonResponse
    {
        $item->load(['workspace.owners', 'collaborators']);

        $known_emails = collect();
        $people = collect();

        foreach ($item->workspace->owners as $owner) {
            $known_emails->push(strtolower($owner->email));
            $people->push($this->personEntry('owner', $owner->id, $owner->full_name, $owner->email, $owner->profile_photo_url, 'accepted', false));
        }

        foreach ($item->collaborators as $collaborator) {
            if ($known_emails->contains(strtolower($collaborator->email))) {
                continue;
            }
            $known_emails->push(strtolower($collaborator->email));
            $people->push($this->personEntry('collaborator', $collaborator->id, $collaborator->full_name, $collaborator->email, $collaborator->profile_photo_url, 'accepted', true));
        }

        $pending_invitations = $item->invitations()
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->get();

        foreach ($pending_invitations as $invitation) {
            if ($known_emails->contains(strtolower($invitation->email))) {
                continue;
            }
            $known_emails->push(strtolower($invitation->email));
            $people->push($this->personEntry('invitation', $invitation->id, null, $invitation->email, null, 'pending', true));
        }

        return response()->json([
            'data' => $people->values(),
        ]);
    }

    /**
     * POST /api/boards/{item}/invitations
     *
     * Bulk-invites one or more email addresses to view this board,
     * resending (rather than duplicating) any invitation still pending for
     * an address. Skips anyone who already has access, whether as a
     * workspace owner, an existing collaborator, or (for a "main" board
     * visible to the whole workspace) any existing workspace member.
     */
    public function store(StoreBoardInvitationRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeInvitationManagement($item, $user, 'invite people to this board');

        $validated = $request->validated();
        $message = $validated['message'] ?? null;

        /** @var array<int, string> $submitted_emails */
        $submitted_emails = $validated['emails'];
        $emails = collect($submitted_emails)->unique(fn (string $email) => strtolower($email));

        $already_has_access = collect();
        $already_has_access = $already_has_access
            ->merge($item->workspace->owners()->whereIn('email', $emails)->pluck('email'))
            ->merge($item->collaborators()->whereIn('email', $emails)->pluck('email'));

        if ($item->board_type === WorkspaceNavigationItem::BOARD_TYPE_MAIN) {
            $already_has_access = $already_has_access->merge(
                $item->workspace->users()->whereIn('email', $emails)->pluck('email')
            );
        }
        $already_has_access = $already_has_access->map(fn (string $email) => strtolower($email));

        $invitations = collect();
        $skipped = collect();

        foreach ($emails as $index => $email) {
            if ($already_has_access->contains(strtolower($email))) {
                $skipped->push(['email' => $email, 'reason' => 'already_has_access']);

                continue;
            }

            $invitation = BoardInvitation::where('board_id', $item->id)
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->whereNull('accepted_at')
                ->first();

            $invitation = DB::transaction(function () use ($item, $invitation, $email, $message, $user) {
                $attributes = [
                    'message' => $message,
                    'invited_by' => $user->id,
                    'expires_at' => now()->addDays(7),
                ];

                if ($invitation) {
                    $invitation->update($attributes);

                    return $invitation;
                }

                return $item->invitations()->create($attributes + ['email' => $email]);
            });

            SendEmailJob::dispatchWithThrottle(new BoardInvitationMail($invitation), $invitation->email, $index);
            $invitations->push($invitation);
        }

        return response()->json([
            'message' => $invitations->isEmpty()
                ? 'No new invitations were sent.'
                : 'Invitations sent successfully.',
            'data' => $invitations->map(fn (BoardInvitation $invitation) => $this->personEntry(
                'invitation', $invitation->id, null, $invitation->email, null, 'pending', true
            ))->values(),
            'skipped' => $skipped->values(),
        ], 201);
    }

    /**
     * DELETE /api/boards/{item}/invitations/{invitation}
     *
     * Revokes a still-pending invitation so its link stops working.
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardInvitation $invitation): JsonResponse
    {
        abort_unless($invitation->board_id === $item->id, 404);

        /** @var User $user */
        $user = $request->user();

        $this->authorizeInvitationManagement($item, $user, 'revoke invitations for this board');

        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => 'Only a pending invitation can be revoked.',
            ])->status(422);
        }

        $invitation->delete();

        return response()->json([
            'message' => 'Invitation revoked.',
        ]);
    }

    /**
     * DELETE /api/boards/{item}/collaborators/{collaborator}
     *
     * Removes a collaborator's explicit access to this board (their
     * workspace membership, if any, is untouched).
     */
    public function removeCollaborator(Request $request, WorkspaceNavigationItem $item, User $collaborator): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeInvitationManagement($item, $user, 'remove people from this board');

        $item->collaborators()->detach($collaborator->id);

        return response()->json([
            'message' => 'Access removed.',
        ]);
    }

    /**
     * Shapes one row of the board's "people with access" roster consistently
     * across the owner/collaborator/pending-invitation cases.
     *
     * @return array<string, mixed>
     */
    private function personEntry(
        string $kind,
        int $id,
        ?string $full_name,
        string $email,
        ?string $profile_photo_url,
        string $status,
        bool $removable
    ): array {
        return [
            'key' => "{$kind}-{$id}",
            'kind' => $kind,
            'id' => $id,
            'full_name' => $full_name,
            'email' => $email,
            'profile_photo_url' => $profile_photo_url,
            'status' => $status,
            'removable' => $removable,
        ];
    }

    /**
     * Gates every invitation-management endpoint: allowed for the board's
     * workspace owner, the board's own creator, or a user holding a
     * {@see PRIVILEGED_GLOBAL_ROLES} role.
     */
    private function authorizeInvitationManagement(WorkspaceNavigationItem $item, User $user, string $action): void
    {
        if ($user->hasRole(self::PRIVILEGED_GLOBAL_ROLES)) {
            return;
        }

        if ($item->created_by_id === $user->id) {
            return;
        }

        $membership = DB::table('workspace_user')
            ->where('workspace_id', $item->workspace_id)
            ->where('user_id', $user->id)
            ->first();

        if (($membership->role ?? null) === 'owner') {
            return;
        }

        throw ValidationException::withMessages([
            'board' => "Only the board's creator, a workspace owner, or an administrator can {$action}.",
        ])->status(403);
    }
}
