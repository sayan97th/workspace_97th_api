<?php

namespace App\Http\Controllers\Admin\AccountSetting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountSetting\UpdateAccountPreferencesRequest;
use App\Http\Requests\Admin\AccountSetting\UpdateProfileSettingsRequest;
use App\Http\Resources\AccountSettingResource;
use App\Models\AccountSetting;
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
}
