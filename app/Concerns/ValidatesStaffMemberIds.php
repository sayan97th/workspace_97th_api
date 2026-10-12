<?php

namespace App\Concerns;

use App\Http\Controllers\AccountTeam\AccountTeamMemberController;
use App\Models\AccountTeam;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Shared by every request that assigns Account Team membership: Teams draw from
 * the internal staff roster, and from guests (client accounts) only on a team
 * that allows guests. See {@see AccountTeamMemberController}
 * for the read-side counterpart of this same role list.
 */
trait ValidatesStaffMemberIds
{
    /**
     * @return array<int, string>
     */
    public static function staffRoles(): array
    {
        return AccountTeam::STAFF_ROLES;
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

    /**
     * A `member_ids.*` rule for one team: staff only, plus guests when the team allows them.
     */
    protected function teamMemberIdRule(bool $allow_guests): In
    {
        $roles = $allow_guests ? [...self::staffRoles(), AccountTeam::GUEST_ROLE] : self::staffRoles();
        $member_ids = User::whereHas('roles', fn ($query) => $query->whereIn('name', $roles))->pluck('id');

        return Rule::in($member_ids);
    }
}
