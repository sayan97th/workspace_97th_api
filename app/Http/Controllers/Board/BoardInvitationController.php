<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardCollaboratorsRequest;
use App\Http\Requests\Board\StoreBoardInvitationRequest;
use App\Jobs\SendEmailJob;
use App\Mail\BoardInvitationMail;
use App\Models\BoardInvitation;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Slack\SlackNotifier;
use App\Support\BoardEditGate;
use App\Support\BoardManagementGate;
use App\Support\VisibleBoards;
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
    public function __construct(private readonly SlackNotifier $slack_notifier) {}

    /**
     * GET /api/boards/{item}/invitations
     *
     * Everyone who was given access to the board (its owners, then explicit
     * board members and guests), followed by anyone with a still-pending
     * invitation. Powers the "Invite to this board" dialog's roster.
     *
     * `workspace_access` lists the people who can also open the board only
     * because they belong to its workspace (every board except a private
     * one), kept apart from `data` so the header's invite count and the
     * Permissions panel keep counting just the people added on purpose.
     *
     * Like {@see VisibleBoards}, a private board is never visible through a
     * workspace role, so workspace owners are only listed as owners of a
     * main or shareable board.
     */
    public function index(WorkspaceNavigationItem $item): JsonResponse
    {
        $item->load(['creator', 'owner', 'workspace.owners', 'collaborators']);

        $is_private = $item->board_type === WorkspaceNavigationItem::BOARD_TYPE_PRIVATE;

        /** @var array<int, string> $workspace_roles user id => workspace role */
        $workspace_roles = DB::table('workspace_user')
            ->where('workspace_id', $item->workspace_id)
            ->pluck('role', 'user_id')
            ->all();

        $known_emails = collect();
        $people = collect();

        // The board's creator leads the roster as an owner, the way monday's Share dialog shows it.
        $owners = collect([$item->creator, $item->owner])->filter();
        if (! $is_private) {
            $owners = $owners->merge($item->workspace->owners);
        }

        foreach ($owners as $owner) {
            if ($known_emails->contains(strtolower($owner->email))) {
                continue;
            }
            $known_emails->push(strtolower($owner->email));
            $people->push($this->userEntry('owner', $owner, false, $workspace_roles[$owner->id] ?? null));
        }

        foreach ($item->collaborators as $collaborator) {
            if ($known_emails->contains(strtolower($collaborator->email))) {
                continue;
            }
            $known_emails->push(strtolower($collaborator->email));
            $people->push($this->userEntry('collaborator', $collaborator, true, $workspace_roles[$collaborator->id] ?? null));
        }

        $pending_invitations = $item->invitations()
            ->with('inviter')
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->get();

        foreach ($pending_invitations as $invitation) {
            if ($known_emails->contains(strtolower($invitation->email))) {
                continue;
            }
            $known_emails->push(strtolower($invitation->email));
            $people->push($this->invitationEntry($invitation));
        }

        $workspace_members = collect();
        if (! $is_private) {
            $workspace_members = $item->workspace->users()
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->reject(fn (User $member) => $member->is_deactivated || $known_emails->contains(strtolower($member->email)))
                ->map(fn (User $member) => $this->userEntry('member', $member, false, $member->pivot->role ?? null))
                ->values();
        }

        return response()->json([
            'data' => $people->values(),
            'workspace_access' => [
                'applies' => ! $is_private,
                'total' => $workspace_members->count(),
                'members' => $workspace_members,
            ],
        ]);
    }

    /**
     * POST /api/boards/{item}/invitations
     *
     * Bulk-invites one or more email addresses to view this board,
     * resending (rather than duplicating) any invitation still pending for
     * an address. Skips anyone who already has access ({@see userIdsWithAccess()}),
     * or, for a "main" board visible to the whole workspace, any existing
     * workspace member.
     * Each invitation also goes out as a Slack direct message when the
     * invited email belongs to someone in the connected Slack workspace.
     */
    public function store(StoreBoardInvitationRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        BoardManagementGate::authorize($item, $user, 'invite people to this board');

        $validated = $request->validated();
        $message = $validated['message'] ?? null;

        /** @var array<int, string> $submitted_emails */
        $submitted_emails = $validated['emails'];
        $emails = collect($submitted_emails)->unique(fn (string $email) => strtolower($email));

        $already_has_access = User::query()
            ->whereIn('id', $this->userIdsWithAccess($item))
            ->pluck('email');

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
            $this->slack_notifier->notifyBoardInvitation($invitation);
            $invitations->push($invitation);
        }

        return response()->json([
            'message' => $invitations->isEmpty()
                ? 'No new invitations were sent.'
                : 'Invitations sent successfully.',
            'data' => $invitations->map(fn (BoardInvitation $invitation) => $this->invitationEntry($invitation->loadMissing('inviter')))->values(),
            'skipped' => $skipped->values(),
        ], 201);
    }

    /**
     * POST /api/boards/{item}/collaborators
     *
     * Gives existing workspace members access right away, no email round
     * trip: the Share dialog's search picks a person, or every member of a
     * team. Their workspace role is untouched, so what they may edit still
     * follows the board's permission ({@see BoardEditGate}).
     * Anyone who already has access is skipped ({@see userIdsWithAccess()}),
     * so a workspace owner is still added to a private board. Everyone added is told on
     * Slack, since no email goes out for this.
     */
    public function storeCollaborators(StoreBoardCollaboratorsRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        BoardManagementGate::authorize($item, $user, 'share this board');

        /** @var array<int, int> $user_ids */
        $user_ids = $request->validated('user_ids');

        $members = $item->workspace->users()->whereIn('users.id', $user_ids)->get();

        if ($members->isEmpty()) {
            throw ValidationException::withMessages([
                'user_ids' => 'Only members of this workspace can be added. Invite anyone else by email.',
            ]);
        }

        $existing_ids = $this->userIdsWithAccess($item);

        $new_members = $members->reject(fn (User $member) => in_array($member->id, $existing_ids, true))->values();

        $item->collaborators()->syncWithoutDetaching(
            $new_members->mapWithKeys(fn (User $member) => [$member->id => ['invited_by' => $user->id]])->all()
        );

        foreach ($new_members as $member) {
            $this->slack_notifier->notifyBoardAccessGranted($item, $member, $user);
        }

        return response()->json([
            'message' => $new_members->isEmpty() ? 'Everyone already has access.' : 'Access granted.',
            'data' => $new_members->map(fn (User $member) => $this->userEntry('collaborator', $member, true, $member->pivot->role ?? null))->values(),
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

        BoardManagementGate::authorize($item, $user, 'revoke invitations for this board');

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

        BoardManagementGate::authorize($item, $user, 'remove people from this board');

        $item->collaborators()->detach($collaborator->id);

        return response()->json([
            'message' => 'Access removed.',
        ]);
    }

    /**
     * Ids of the people who can already open the board without being added again: its creator,
     * its owner and its collaborators, plus the workspace owners for a main or shareable board.
     *
     * A private board is never visible through a workspace role ({@see VisibleBoards}), so a
     * workspace owner must still be added to it. Treating them as "already has access" there
     * skipped them with no collaborator row, and they got "This board is private".
     *
     * @return array<int, int>
     */
    private function userIdsWithAccess(WorkspaceNavigationItem $item): array
    {
        $user_ids = $item->collaborators()->pluck('users.id')
            ->push($item->created_by_id, $item->owner_id);

        if ($item->board_type !== WorkspaceNavigationItem::BOARD_TYPE_PRIVATE) {
            $user_ids = $user_ids->merge($item->workspace->owners()->pluck('users.id'));
        }

        return $user_ids->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * A roster row for someone with an account. `$workspace_role` is their
     * role in the board's workspace, null when they are not a member of it.
     *
     * @return array<string, mixed>
     */
    private function userEntry(string $kind, User $person, bool $removable, ?string $workspace_role = null): array
    {
        return $this->personEntry($kind, $person->id, $person->full_name, $person->email, $person->profile_photo_url, 'accepted', $removable, $person->job_title) + [
            'workspace_role' => $workspace_role,
            // Accepted board invitees join the workspace as a viewer, someone outside it is a guest too.
            'is_guest' => $workspace_role === null || $workspace_role === 'viewer',
        ];
    }

    /**
     * A roster row for a still-pending invitation, with when it was last
     * sent (a resend refreshes it) and when its link stops working.
     *
     * @return array<string, mixed>
     */
    private function invitationEntry(BoardInvitation $invitation): array
    {
        return $this->personEntry('invitation', $invitation->id, null, $invitation->email, null, 'pending', true) + [
            'workspace_role' => null,
            'is_guest' => true,
            'invited_at' => $invitation->updated_at?->toIso8601String(),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'invited_by_name' => $invitation->inviter?->full_name,
        ];
    }

    /**
     * Shapes one row of the board's "people with access" roster consistently
     * across the owner, collaborator, workspace member and pending invitation
     * cases. `access_via` says where the access comes from.
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
        bool $removable,
        ?string $job_title = null
    ): array {
        return [
            'key' => "{$kind}-{$id}",
            'kind' => $kind,
            'id' => $id,
            'full_name' => $full_name,
            'email' => $email,
            'profile_photo_url' => $profile_photo_url,
            'job_title' => $job_title,
            'status' => $status,
            'removable' => $removable,
            'access_via' => match ($kind) {
                'owner' => 'owner',
                'member' => 'workspace',
                'invitation' => 'invitation',
                default => 'board',
            },
        ];
    }
}
