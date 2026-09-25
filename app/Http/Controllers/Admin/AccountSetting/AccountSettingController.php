<?php

namespace App\Http\Controllers\Admin\AccountSetting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountSetting\UpdateAccountDefaultsRequest;
use App\Http\Requests\Admin\AccountSetting\UpdateAccountPreferencesRequest;
use App\Http\Requests\Admin\AccountSetting\UpdateProfileSettingsRequest;
use App\Http\Resources\AccountSettingResource;
use App\Models\AccountSetting;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;

class AccountSettingController extends Controller
{
    /**
     * GET /api/admin/account-settings
     */
    public function show(): JsonResponse
    {
        return response()->json(new AccountSettingResource(AccountSetting::current()));
    }

    /**
     * PATCH /api/admin/account-settings/profile
     */
    public function updateProfile(UpdateProfileSettingsRequest $request): JsonResponse
    {
        $settings = AccountSetting::current();
        $settings->update($request->validated());

        return response()->json([
            'message' => 'Profile settings updated successfully.',
            'account_settings' => new AccountSettingResource($settings),
        ]);
    }

    /**
     * PATCH /api/admin/account-settings/preferences
     */
    public function updatePreferences(UpdateAccountPreferencesRequest $request): JsonResponse
    {
        $settings = AccountSetting::current();
        $settings->update($request->validated());

        return response()->json([
            'message' => 'Account preferences updated successfully.',
            'account_settings' => new AccountSettingResource($settings),
        ]);
    }

    /**
     * PATCH /api/admin/account-settings/defaults
     *
     * Account defaults for new users (timezone, language, date and time format, first day
     * of the week). Applied when a user is created, see `User::booted()`, so existing users
     * keep the preferences they already chose.
     */
    public function updateDefaults(UpdateAccountDefaultsRequest $request): JsonResponse
    {
        $settings = AccountSetting::current();
        $settings->update($request->validated());

        AuditLogger::log('account.defaults_updated', 'Updated the account defaults for new users.', $request->user(), $request->validated());

        return response()->json([
            'message' => 'Account defaults updated successfully.',
            'account_settings' => new AccountSettingResource($settings),
        ]);
    }
}
