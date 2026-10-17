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
     * Everyone who already has access to the board (workspace owners, then
     * explicit board collaborators), followed by anyone with a still-pending
     * invitation. Powers the "Invite to this board" dialog's roster.
     */
    public function index(WorkspaceNavigationItem $item): JsonResponse
    {
        $item->load(['creator', 'workspace.owners', 'collaborators']);

        $known_emails = collect();
        $people = collect();

        // The board's creator leads the roster as an owner, the way monday's Share dialog shows it.
        $owners = collect([$item->creator])->filter()->merge($item->workspace->owners);

        foreach ($owners as $owner) {
            if ($known_emails->contains(strtolower($owner->email))) {
                continue;
            }
            $known_emails->push(strtolower($owner->email));
            $people->push($this->userEntry('owner', $owner, false));
        }

        foreach ($item->collaborators as $collaborator) {
            if ($known_emails->contains(strtolower($collaborator->email))) {
                continue;
            }
            $known_emails->push(strtolower($collaborator->email));
            $people->push($this->userEntry('collaborator', $collaborator, true));
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
            $this->slack_notifier->notifyBoardInvitation($invitation);
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
     * POST /api/boards/{item}/collaborators
     *
     * Gives existing workspace members access right away, no email round
     * trip: the Share dialog's search picks a person, or every member of a
     * team. Their workspace role is untouched, so what they may edit still
     * follows the board's permission ({@see BoardEditGate}).
     * Anyone who already has access is skipped. Everyone added is told on
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

        $existing_ids = $item->collaborators()->pluck('users.id')
            ->merge($item->workspace->owners()->pluck('users.id'))
            ->push($item->created_by_id)
            ->filter()
            ->all();

        $new_members = $members->reject(fn (User $member) => in_array($member->id, $existing_ids, true))->values();

        $item->collaborators()->syncWithoutDetaching(
            $new_members->mapWithKeys(fn (User $member) => [$member->id => ['invited_by' => $user->id]])->all()
        );

        foreach ($new_members as $member) {
            $this->slack_notifier->notifyBoardAccessGranted($item, $member, $user);
        }

        return response()->json([
            'message' => $new_members->isEmpty() ? 'Everyone already has access.' : 'Access granted.',
            'data' => $new_members->map(fn (User $member) => $this->userEntry('collaborator', $member, true))->values(),
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
     * A roster row for someone with an account.
     *
     * @return array<string, mixed>
     */
    private function userEntry(string $kind, User $person, bool $removable): array
    {
        return $this->personEntry($kind, $person->id, $person->full_name, $person->email, $person->profile_photo_url, 'accepted', $removable, $person->job_title);
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
        ];
    }
}
