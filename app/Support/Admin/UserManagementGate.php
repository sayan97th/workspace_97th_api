<?php

namespace App\Support\Admin;

use App\Models\User;

/**
 * Who may manage (deactivate, delete, bulk edit) which account. A super admin may manage
 * anyone; a plain admin only client tier accounts, never another staff member. Shared by
 * the single user endpoints and the bulk actions so both enforce the same rule.
 */
class UserManagementGate
{
    public static function canManage(User $actor, User $target): bool
    {
        if ($actor->hasRole('super_admin')) {
            return true;
        }

        return $target->roles->pluck('name')->intersect(AdminUserQuery::STAFF_ROLES)->isEmpty();
    }
}
