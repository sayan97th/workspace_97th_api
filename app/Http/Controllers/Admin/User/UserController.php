<?php

namespace App\Http\Controllers\Admin\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\InviteUserRequest;
use App\Http\Requests\Admin\User\SetUserPasswordRequest;
use App\Http\Requests\Admin\User\UpdateUserRequest;
use App\Http\Resources\StaffInvitationResource;
use App\Http\Resources\UserWithRolesResource;
use App\Jobs\SendEmailJob;
use App\Mail\StaffInvitationMail;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class UserController extends Controller
{
    private const STAFF_ROLES = ['super_admin', 'admin', 'staff'];

    private const ALLOWED_SORT_FIELDS = ['name', 'email', 'role', 'department', 'status', 'created_at'];

    /** Highest-privilege first, used to rank a user's role for sorting when they hold more than one. */
    private const ROLE_SORT_PRIORITY = ['super_admin', 'admin', 'staff', 'client'];

    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * GET /api/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $search = $request->query('search');
        $role = $request->query('role');
        $sort_field = $request->query('sort_field', 'created_at');
        $sort_direction = $request->query('sort_direction', 'desc');
        $email_status = $request->query('email_status');
        $account_status = $request->query('account_status');
        $department = $request->query('department');

        if ($type !== null && ! \in_array($type, ['staff', 'client'], true)) {
            return response()->json([
                'message' => 'The type field must be staff or client.',
                'errors' => ['type' => ['The selected type is invalid.']],
            ], 422);
        }

        if (! \in_array($sort_field, self::ALLOWED_SORT_FIELDS, true)) {
            return response()->json([
                'message' => 'The sort_field value is invalid.',
                'errors' => ['sort_field' => ['The selected sort field is invalid.']],
            ], 422);
        }

        if (! \in_array($sort_direction, ['asc', 'desc'], true)) {
            $sort_direction = 'asc';
        }

        if ($role !== null && ! \in_array($role, self::STAFF_ROLES, true) && $role !== 'client') {
            $role = null;
        }

        $query = User::with(['roles:id,name,display_name', 'department:id,name']);
        $this->applySort($query, $sort_field, $sort_direction);

        if ($type === 'staff') {
            $query->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES));
        } elseif ($type === 'client') {
            $query->whereHas('roles', fn ($q) => $q->where('name', 'client'))
                ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES));
        }

        if ($role !== null) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        if ($email_status === 'verified') {
            $query->whereNotNull('email_verified_at');
        } elseif ($email_status === 'unverified') {
            $query->whereNull('email_verified_at');
        }

        if ($account_status === 'active') {
            $query->where('is_active', true);
        } elseif ($account_status === 'disabled') {
            $query->where('is_active', false);
        } elseif ($account_status === 'deleted') {
            $query->onlyTrashed();
        }

        if ($department === 'unassigned') {
            // Not just `whereNull('department_id')`: a department that's been soft-deleted
            // still leaves its old members' `department_id` column set, but the `department`
            // relation (and therefore `UserWithRolesResource`) already treats them as
            // unassigned since the relation query excludes trashed rows — this filter needs
            // to agree with what the UI actually shows.
            $query->whereDoesntHave('department');
        } elseif ($department !== null && ctype_digit($department)) {
            $query->where('department_id', (int) $department);
        }

        // Clamped to [1, MAX_PER_PAGE] so a stray 0/negative value never reaches paginate(),
        // and so the page size stays reasonable for a UI table regardless of what's requested.
        $per_page = max(1, min((int) $request->query('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));
        $users = $query->paginate($per_page);

        return response()->json([
            'data' => UserWithRolesResource::collection($users->items()),
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'total' => $users->total(),
        ]);
    }

    /**
     * GET /api/admin/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        $user->load(['roles:id,name,display_name', 'department:id,name']);

        return response()->json(new UserWithRolesResource($user));
    }

    /**
     * PATCH /api/admin/users/{user}
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user->update($request->validated());

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => new UserWithRolesResource($user->fresh(['roles:id,name,display_name', 'department:id,name'])),
        ]);
    }

    /**
     * PATCH /api/admin/users/{user}/ban
     */
    public function ban(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($actor->id === $user->id) {
            return response()->json(['message' => 'You cannot disable your own account.'], 422);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This account is already disabled.'], 409);
        }

        if (! $this->actorCanManage($actor, $user)) {
            return response()->json(['message' => 'You do not have permission to disable this account.'], 403);
        }

        $user->update(['is_active' => false]);

        AuditLogger::log('user.deactivated', "Deactivated {$user->full_name}'s account.", $actor, ['target_user_id' => $user->id]);

        return response()->json([
            'message' => 'User account has been disabled.',
            'user' => new UserWithRolesResource($user->fresh(['roles:id,name,display_name', 'department:id,name'])),
        ]);
    }

    /**
     * PATCH /api/admin/users/{user}/unban
     */
    public function unban(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($user->is_active) {
            return response()->json(['message' => 'This account is already active.'], 409);
        }

        if (! $this->actorCanManage($actor, $user)) {
            return response()->json(['message' => 'You do not have permission to re-enable this account.'], 403);
        }

        $user->update(['is_active' => true]);

        AuditLogger::log('user.reactivated', "Reactivated {$user->full_name}'s account.", $actor, ['target_user_id' => $user->id]);

        return response()->json([
            'message' => 'User account has been re-enabled.',
            'user' => new UserWithRolesResource($user->fresh(['roles:id,name,display_name', 'department:id,name'])),
        ]);
    }

    /**
     * DELETE /api/admin/users/{user}
     *
     * Soft deletes the account: the person can no longer sign in and disappears from every
     * roster and picker, but the row (name, email, photo) is kept so their past comments,
     * updates and assignments still show who they were, faded. Undo it with {@see restore()}.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($actor->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        if (! $this->actorCanManage($actor, $user)) {
            return response()->json(['message' => 'You do not have permission to delete this account.'], 403);
        }

        AuditLogger::log('user.deleted', "Deleted {$user->full_name}'s account.", $actor, [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
        ]);

        $user->delete();

        return response()->json([
            'message' => 'User account has been deleted.',
        ]);
    }

    /**
     * PATCH /api/admin/users/{user}/restore
     *
     * Brings a deleted account back, exactly as it was: same roles, workspaces and history.
     */
    public function restore(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! $user->trashed()) {
            return response()->json(['message' => 'This account has not been deleted.'], 409);
        }

        if (! $this->actorCanManage($actor, $user)) {
            return response()->json(['message' => 'You do not have permission to restore this account.'], 403);
        }

        $user->restore();

        AuditLogger::log('user.restored', "Restored {$user->full_name}'s account.", $actor, ['target_user_id' => $user->id]);

        return response()->json([
            'message' => 'User account has been restored.',
            'user' => new UserWithRolesResource($user->fresh(['roles:id,name,display_name', 'department:id,name'])),
        ]);
    }

    /**
     * PATCH /api/admin/users/{user}/password
     *
     * Sets the account's password directly, bypassing the emailed reset flow, for cases
     * where an administrator needs to hand someone working credentials right away.
     */
    public function setPassword(SetUserPasswordRequest $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($actor->id === $user->id) {
            return response()->json(['message' => 'Use your profile settings to change your own password.'], 422);
        }

        if (! $this->actorCanManage($actor, $user)) {
            return response()->json(['message' => "You do not have permission to change this account's password."], 403);
        }

        $user->update(['password' => $request->validated('password')]);

        AuditLogger::log('user.password_set', "Set a new password for {$user->full_name}'s account.", $actor, ['target_user_id' => $user->id]);

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }

    /**
     * POST /api/admin/users/{user}/send-password-reset-link
     *
     * Emails the account the same reset link they'd get from "Forgot password", so the
     * user picks their own next password instead of the administrator relaying one.
     */
    public function sendPasswordResetLink(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($actor->id === $user->id) {
            return response()->json(['message' => 'Use the sign-in page to reset your own password.'], 422);
        }

        if (! $this->actorCanManage($actor, $user)) {
            return response()->json(['message' => "You do not have permission to reset this account's password."], 403);
        }

        Password::sendResetLink(['email' => $user->email]);

        AuditLogger::log('user.password_reset_sent', "Sent a password reset link to {$user->full_name}.", $actor, ['target_user_id' => $user->id]);

        return response()->json([
            'message' => "A password reset email has been sent to {$user->email}.",
        ]);
    }

    /**
     * POST /api/admin/users/invite
     *
     * Invites a brand-new platform user by email, with a role (and optionally a department)
     * pre-assigned before they ever register. Resends (updates in place) rather than
     * duplicates any invitation still pending for that address, mirroring
     * `Workspace\WorkspaceInvitationController::store()`'s idiom.
     */
    public function invite(InviteUserRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $role = (string) $validated['role'];

        if (! $this->actorCanInviteAs($actor, $role)) {
            return response()->json([
                'message' => "You do not have permission to invite someone as {$role}.",
            ], 403);
        }

        $existing = StaffInvitation::whereRaw('LOWER(email) = ?', [strtolower($validated['email'])])
            ->whereNull('accepted_at')
            ->first();

        $attributes = [
            'email' => $validated['email'],
            'role' => $role,
            'department_id' => $validated['department_id'] ?? null,
            'message' => $validated['message'] ?? null,
            'invited_by' => $actor->id,
            'expires_at' => now()->addDays(7),
        ];

        $invitation = $existing ? tap($existing)->update($attributes) : StaffInvitation::create($attributes);

        SendEmailJob::dispatchWithThrottle(new StaffInvitationMail($invitation->fresh('inviter')), $invitation->email);

        AuditLogger::log('user.invited', "Invited {$invitation->email} as {$role}.", $actor, ['email' => $invitation->email, 'role' => $role]);

        return response()->json([
            'message' => 'Invitation sent successfully.',
            'invitation' => new StaffInvitationResource($invitation),
        ], 201);
    }

    /**
     * Only a super_admin may invite someone directly into a super_admin/admin role; a plain
     * admin may only invite staff/client accounts, matching {@see actorCanManage()}'s same
     * "admin can't touch admin-tier accounts" boundary.
     */
    private function actorCanInviteAs(User $actor, string $role): bool
    {
        if ($actor->hasRole('super_admin')) {
            return true;
        }

        return ! in_array($role, ['super_admin', 'admin'], true);
    }

    /**
     * Determine if the acting user is allowed to change the target user's active status.
     *
     * Super admins can manage anyone; plain admins may only manage client-only accounts.
     */
    private function actorCanManage(User $actor, User $target): bool
    {
        if ($actor->hasRole('super_admin')) {
            return true;
        }

        $target_roles = $target->roles->pluck('name');

        return $target_roles->intersect(self::STAFF_ROLES)->isEmpty();
    }

    /**
     * Orders the user list by one of the columns the "Users" table renders. `role` and
     * `department` aren't plain columns on `users` (roles are a many-to-many relation,
     * department names live on a soft-deletable related table), so each gets its own
     * comparable expression rather than a plain `orderBy()`.
     *
     * @param  Builder<User>  $query
     */
    private function applySort(Builder $query, string $sort_field, string $sort_direction): void
    {
        switch ($sort_field) {
            case 'name':
                $query->orderBy('first_name', $sort_direction)->orderBy('last_name', $sort_direction);
                break;

            case 'status':
                $query->orderBy('is_active', $sort_direction);
                break;

            case 'department':
                // Left join (not the `department` relation) so users with no department, or
                // whose department was soft-deleted, still appear, sorted by name being null.
                $query->select('users.*')
                    ->leftJoin('departments', function ($join) {
                        $join->on('departments.id', '=', 'users.department_id')
                            ->whereNull('departments.deleted_at');
                    })
                    ->orderBy('departments.name', $sort_direction);
                break;

            case 'role':
                $case_when = collect(self::ROLE_SORT_PRIORITY)
                    ->map(fn (string $role, int $index) => "WHEN EXISTS (SELECT 1 FROM user_role INNER JOIN roles ON roles.id = user_role.role_id WHERE user_role.user_id = users.id AND roles.name = '{$role}') THEN ".($index + 1))
                    ->implode(' ');
                $query->orderByRaw("(CASE {$case_when} ELSE ".(count(self::ROLE_SORT_PRIORITY) + 1).' END) '.$sort_direction);
                break;

            case 'email':
                $query->orderBy('email', $sort_direction);
                break;

            default:
                $query->orderBy('created_at', $sort_direction);
                break;
        }
    }
}
