<?php

namespace App\Concerns;

use App\Http\Controllers\AccountTeam\AccountTeamMemberController;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Shared by every request that assigns Account Team membership: Teams only
 * ever draw from — and only ever display — the internal staff roster, never
 * client-portal accounts. See {@see AccountTeamMemberController}
 * for the read-side counterpart of this same role list.
 */
trait ValidatesStaffMemberIds
{
    /**
     * @return array<int, string>
     */
    public static function staffRoles(): array
    {
        return ['super_admin', 'admin', 'staff'];
    }

    /**
     * A `member_ids.*` rule that only accepts ids of users holding a staff role.
     */
    protected function staffMemberIdRule(): In
    {
        $staff_ids = User::whereHas('roles', fn ($query) => $query->whereIn('name', self::staffRoles()))
            ->pluck('id');

        return Rule::in($staff_ids);
    }
}
