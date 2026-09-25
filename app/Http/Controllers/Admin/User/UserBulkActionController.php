<?php

namespace App\Http\Controllers\Admin\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\BulkUserActionRequest;
use App\Models\User;
use App\Support\Admin\UserManagementGate;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/admin/users/bulk
 *
 * Applies one action to many selected users of Administration > Users. Each user is checked
 * with the same rules as the single user endpoints: nobody can change their own account
 * from here, a plain admin can only manage client tier accounts, and only a super admin can
 * change platform roles. Users that fail a check are skipped and counted, never an error, so
 * a mixed selection still updates everyone it can.
 */
class UserBulkActionController extends Controller
{
    private const ROLES = ['super_admin', 'admin', 'staff', 'client'];

    public function __invoke(BulkUserActionRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action = (string) $request->validated('action');

        if ($action === 'set_role' && ! $actor->hasRole('super_admin')) {
            return response()->json(['message' => 'Only a super admin can change user roles.'], 403);
        }

        $users = User::with('roles:id,name')
            ->whereIn('id', $request->validated('user_ids'))
            ->get();

        $updated_ids = [];
        $skipped_count = count($request->validated('user_ids')) - $users->count();

        DB::transaction(function () use ($users, $actor, $action, $request, &$updated_ids, &$skipped_count) {
            foreach ($users as $user) {
                if ($user->id === $actor->id || ! UserManagementGate::canManage($actor, $user)) {
                    $skipped_count++;

                    continue;
                }

                $changed = match ($action) {
                    'set_department' => $this->setDepartment($user, $request->validated('department_id')),
                    'set_role' => $this->setRole($user, (string) $request->validated('role')),
                    'deactivate' => $this->setActive($user, false),
                    'reactivate' => $this->setActive($user, true),
                };

                if ($changed) {
                    $updated_ids[] = $user->id;
                } else {
                    $skipped_count++;
                }
            }
        });

        $updated_count = count($updated_ids);

        if ($updated_count > 0) {
            AuditLogger::log(
                "user.bulk_{$action}",
                "Bulk {$this->actionLabel($action)} for {$updated_count} user(s).",
                $actor,
                array_filter([
                    'user_ids' => $updated_ids,
                    'department_id' => $action === 'set_department' ? $request->validated('department_id') : null,
                    'role' => $action === 'set_role' ? $request->validated('role') : null,
                ], fn ($value) => $value !== null),
            );
        }

        return response()->json([
            'message' => $updated_count === 1 ? '1 user updated.' : "{$updated_count} users updated.",
            'updated_count' => $updated_count,
            'skipped_count' => $skipped_count,
            'updated_ids' => $updated_ids,
        ]);
    }

    private function setDepartment(User $user, ?int $department_id): bool
    {
        if ($user->department_id === $department_id) {
            return false;
        }

        $user->update(['department_id' => $department_id]);

        return true;
    }

    private function setRole(User $user, string $role): bool
    {
        $current = $user->roles->pluck('name')->all();
        if ($current === [$role]) {
            return false;
        }

        foreach (array_intersect($current, self::ROLES) as $existing) {
            if ($existing !== $role) {
                $user->removeRole($existing);
            }
        }
        if (! in_array($role, $current, true)) {
            $user->assignRole($role);
        }

        return true;
    }

    private function setActive(User $user, bool $is_active): bool
    {
        if ($user->trashed() || $user->is_active === $is_active) {
            return false;
        }

        $user->update(['is_active' => $is_active]);

        return true;
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'set_department' => 'department change',
            'set_role' => 'role change',
            'deactivate' => 'deactivation',
            default => 'reactivation',
        };
    }
}
