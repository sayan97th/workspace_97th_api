<?php

namespace App\Http\Controllers\Admin\AccountSetting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountSetting\UpdateAuthenticationSettingsRequest;
use App\Http\Resources\AccountSettingResource;
use App\Models\AccountSetting;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class AuthenticationSettingsController extends Controller
{
    /**
     * PATCH /api/admin/account-settings/authentication
     *
     * Persists the account-wide authentication policy. `two_factor_enforced` and
     * `ip_restriction_enabled`/`ip_ranges` and `guest_approval_enabled`/`approved_domains`
     * are genuinely enforced elsewhere in the request lifecycle; `saml_sso_enabled` and
     * `scim_enabled` are configuration storage only, there is no live SAML assertion
     * consumer or SCIM server behind them.
     */
    public function update(UpdateAuthenticationSettingsRequest $request): JsonResponse
    {
        $settings = AccountSetting::current();
        $settings->update($request->validated());

        AuditLogger::log(
            'authentication_settings.updated',
            'Updated the account authentication policy.',
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'message' => 'Authentication settings updated successfully.',
            'account_settings' => new AccountSettingResource($settings),
        ]);
    }

    /**
     * POST /api/admin/account-settings/scim-token/rotate
     */
    public function rotateScimToken(): JsonResponse
    {
        $settings = AccountSetting::current();
        $settings->update(['scim_token' => Str::random(48)]);

        AuditLogger::log('authentication_settings.scim_token_rotated', 'Rotated the SCIM API token.', request()->user());

        return response()->json([
            'message' => 'SCIM token rotated successfully.',
            'account_settings' => new AccountSettingResource($settings),
        ]);
    }
}
