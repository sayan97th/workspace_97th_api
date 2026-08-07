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
use App\Support\WorkspacePermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceInvitationController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    private const SORTABLE_FIELDS = ['email', 'role', 'status', 'expires_at', 'created_at'];

    /**
     * GET /api/workspaces/{workspace}/invitations
     *
     * Every invitation ever sent for the workspace (pending, expired or
     * accepted) — the "Sent invitations" view's table. Server-searched by
     * email, filterable by `status`/`role`, sortable by `sort_field`/
     * `sort_direction`, narrowable to a `date_from`/`date_to` "invited at"
     * range, and paginated. Restricted to owners, like `store()`.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $membership = $this->membershipFor($workspace, $user->id);
        if (($membership->role ?? null) !== 'owner') {
            throw ValidationException::withMessages([
                'workspace' => 'Only the workspace owner can view sent invitations.',
            ])->status(403);
        }

        $per_page = max(1, min((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));

        $query = $workspace->invitations()->with('inviter');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where('email', 'LIKE', '%'.$search.'%');
        }

        $status = (string) $request->query('status', '');
        if (in_array($status, ['pending', 'expired', 'accepted'], true)) {
            match ($status) {
                'accepted' => $query->whereNotNull('accepted_at'),
                'expired' => $query->whereNull('accepted_at')->where('expires_at', '<=', now()),
                'pending' => $query->whereNull('accepted_at')
                    ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
            };
        }

        $role = (string) $request->query('role', '');
        if (in_array($role, WorkspacePermissionCatalog::invitableRoleIds(), true)) {
            $query->where('role', $role);
        }

        $date_from = (string) $request->query('date_from', '');
        if ($date_from !== '') {
            $query->whereDate('created_at', '>=', $date_from);
        }

        $date_to = (string) $request->query('date_to', '');
        if ($date_to !== '') {
            $query->whereDate('created_at', '<=', $date_to);
        }

        $sort_field = (string) $request->query('sort_field', 'created_at');
        if (! in_array($sort_field, self::SORTABLE_FIELDS, true)) {
            $sort_field = 'created_at';
        }
        $sort_direction = strtolower((string) $request->query('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sort_field === 'status') {
            // Not a real column — rank pending < expired < accepted so the direction toggle still reads naturally.
            $query->orderByRaw(
                "(CASE WHEN accepted_at IS NOT NULL THEN 2 WHEN expires_at IS NOT NULL AND expires_at <= ? THEN 1 ELSE 0 END) {$sort_direction}",
                [now()]
            );
        } else {
            $query->orderBy($sort_field, $sort_direction);
        }

        $paginator = $query->paginate($per_page);

        return response()->json([
            'data' => WorkspaceInvitationResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

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
     * DELETE /api/workspaces/{workspace}/invitations/{invitation}
     *
     * Revokes a still-pending invitation so its link stops working. Restricted
     * to owners; an already-expired or already-accepted invitation can't be
     * revoked (nothing left to revoke — decline/expiry already settled it).
     */
    public function destroy(Request $request, Workspace $workspace, WorkspaceInvitation $invitation): JsonResponse
    {
        abort_unless($invitation->workspace_id === $workspace->id, 404);

        /** @var User $user */
        $user = $request->user();

        $membership = $this->membershipFor($workspace, $user->id);
        if (($membership->role ?? null) !== 'owner') {
            throw ValidationException::withMessages([
                'workspace' => 'Only the workspace owner can revoke invitations.',
            ])->status(403);
        }

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
