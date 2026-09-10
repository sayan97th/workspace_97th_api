<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Models\Workspace;

/**
 * Enrolls a user into every "home" workspace (`is_home = true`, e.g.
 * "Fulfillment") — the shared company workspace every account should land
 * in, whether they just registered or their app-level role just changed.
 *
 * Staff/admin accounts are enrolled as "owner" so they can jointly manage the
 * workspace the same way the rest of the internal team already does; every
 * other account (self-registered clients) is enrolled as a plain "member" so
 * a public sign-up can't rename/change-type/delete an internal workspace.
 */
class HomeWorkspaceEnrollmentService
{
    /**
     * App-level roles treated as internal team members for this purpose.
     *
     * @var array<int, string>
     */
    private const STAFF_ROLES = ['staff', 'admin', 'super_admin'];

    /**
     * Enrolls (or re-syncs) the user's membership on every home workspace,
     * granting "owner" to staff/admin accounts and "member" to everyone else.
     * Idempotent — safe to call again after a user's app-level role changes.
     */
    public function enroll(User $user): void
    {
        $role = $user->hasRole(self::STAFF_ROLES) ? 'owner' : 'member';

        Workspace::where('is_home', true)->get()->each(
            fn (Workspace $workspace) => $workspace->users()->syncWithoutDetaching([
                $user->id => ['role' => $role, 'is_recent' => true],
            ])
        );
    }
}
