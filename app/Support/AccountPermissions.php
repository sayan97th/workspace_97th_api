<?php

namespace App\Support;

use App\Models\AccountSetting;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Account wide "who can do what" matrix, the monday.com Administration > Permissions page.
 * Admins and super admins can always do everything; the matrix only restricts the `staff`
 * and `client` tiers. Every permission defaults to allowed, so an account that never opened
 * the page behaves exactly as before.
 */
class AccountPermissions
{
    public const CREATE_WORKSPACES = 'create_workspaces';

    public const CREATE_BOARDS = 'create_boards';

    public const DELETE_BOARDS = 'delete_boards';

    public const INVITE_MEMBERS = 'invite_members';

    public const EXPORT_DATA = 'export_data';

    public const USE_INTEGRATIONS = 'use_integrations';

    /** Roles whose permissions the matrix controls, highest privilege first. */
    public const CONFIGURABLE_ROLES = ['staff', 'client'];

    /** Roles that always hold every permission and never appear in the matrix. */
    public const UNRESTRICTED_ROLES = ['super_admin', 'admin'];

    /**
     * @var array<string, array{label: string, description: string}>
     */
    public const DEFINITIONS = [
        self::CREATE_WORKSPACES => [
            'label' => 'Create workspaces',
            'description' => 'Create new workspaces in the account.',
        ],
        self::CREATE_BOARDS => [
            'label' => 'Create boards',
            'description' => 'Create boards, docs and dashboards inside a workspace.',
        ],
        self::DELETE_BOARDS => [
            'label' => 'Delete boards and folders',
            'description' => 'Permanently delete boards and folders from a workspace.',
        ],
        self::INVITE_MEMBERS => [
            'label' => 'Invite members',
            'description' => 'Invite people to workspaces and boards by email.',
        ],
        self::EXPORT_DATA => [
            'label' => 'Export data',
            'description' => 'Export boards, item updates and the feed to Excel or CSV.',
        ],
        self::USE_INTEGRATIONS => [
            'label' => 'Connect integrations',
            'description' => 'Connect a personal account to integrations such as Slack.',
        ],
    ];

    /**
     * The full matrix, stored overrides merged over the "everything allowed" default.
     *
     * @return array<string, array<string, bool>>
     */
    public static function matrix(?AccountSetting $settings = null): array
    {
        $settings ??= AccountSetting::query()->first();
        $stored = $settings?->account_permissions ?? [];

        $matrix = [];
        foreach (self::CONFIGURABLE_ROLES as $role) {
            foreach (array_keys(self::DEFINITIONS) as $key) {
                $matrix[$role][$key] = (bool) ($stored[$role][$key] ?? true);
            }
        }

        return $matrix;
    }

    /**
     * Whether `$user` may do `$key`. A user holding several roles gets the most permissive
     * answer, and a user with no platform role is treated as a client.
     */
    public static function allows(User $user, string $key): bool
    {
        if ($user->hasRole(self::UNRESTRICTED_ROLES)) {
            return true;
        }

        $matrix = self::matrix();
        $roles = array_values(array_intersect(self::CONFIGURABLE_ROLES, $user->roles->pluck('name')->all()));
        if ($roles === []) {
            $roles = ['client'];
        }

        foreach ($roles as $role) {
            if ($matrix[$role][$key] ?? true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every permission key mapped to whether `$user` holds it, for the frontend to hide
     * controls the API would reject anyway.
     *
     * @return array<string, bool>
     */
    public static function forUser(User $user): array
    {
        return collect(array_keys(self::DEFINITIONS))
            ->mapWithKeys(fn (string $key) => [$key => self::allows($user, $key)])
            ->all();
    }

    /**
     * Aborts with a 403 naming the missing permission when `$user` lacks it.
     */
    public static function authorize(User $user, string $key): void
    {
        if (! self::allows($user, $key)) {
            $label = strtolower(self::DEFINITIONS[$key]['label'] ?? $key);

            throw new HttpException(403, "Your account role is not allowed to {$label}. Ask an account admin for access.");
        }
    }
}
