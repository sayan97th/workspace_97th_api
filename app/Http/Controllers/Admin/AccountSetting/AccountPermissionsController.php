<?php

namespace App\Http\Controllers\Admin\AccountSetting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountSetting\UpdateAccountPermissionsRequest;
use App\Models\AccountSetting;
use App\Support\AccountPermissions;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration > Security > Permissions, the account wide role by permission matrix.
 * Enforced by the `account.permission` route middleware, see {@see AccountPermissions}.
 */
class AccountPermissionsController extends Controller
{
    /**
     * GET /api/admin/account-settings/permissions
     */
    public function show(): JsonResponse
    {
        return response()->json($this->payload(AccountSetting::current()));
    }

    /**
     * PATCH /api/admin/account-settings/permissions
     */
    public function update(UpdateAccountPermissionsRequest $request): JsonResponse
    {
        $settings = AccountSetting::current();
        $matrix = AccountPermissions::matrix($settings);
        $changes = [];

        foreach ($request->validated('permissions') as $role => $permissions) {
            foreach ($permissions as $key => $is_allowed) {
                if ($matrix[$role][$key] !== (bool) $is_allowed) {
                    $changes[] = ['role' => $role, 'permission' => $key, 'allowed' => (bool) $is_allowed];
                }
                $matrix[$role][$key] = (bool) $is_allowed;
            }
        }

        $settings->update(['account_permissions' => $matrix]);

        foreach ($changes as $change) {
            $label = AccountPermissions::DEFINITIONS[$change['permission']]['label'];
            AuditLogger::log(
                'account_permissions.updated',
                ($change['allowed'] ? 'Allowed' : 'Blocked')." \"{$label}\" for the {$change['role']} role.",
                $request->user(),
                $change,
            );
        }

        return response()->json([
            'message' => 'Account permissions updated.',
            ...$this->payload($settings),
        ]);
    }

    /**
     * GET /api/account-permissions/me
     *
     * The caller's own effective permissions, for any signed in user, so the app can hide
     * controls the API would reject.
     */
    public function mine(Request $request): JsonResponse
    {
        return response()->json(['permissions' => AccountPermissions::forUser($request->user())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AccountSetting $settings): array
    {
        return [
            'definitions' => collect(AccountPermissions::DEFINITIONS)
                ->map(fn (array $definition, string $key) => ['key' => $key, ...$definition])
                ->values(),
            'roles' => AccountPermissions::CONFIGURABLE_ROLES,
            'matrix' => AccountPermissions::matrix($settings),
        ];
    }
}
