<?php

namespace App\Http\Controllers\Admin\AccountSetting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountSetting\StoreEmailHeaderRequest;
use App\Http\Requests\Admin\AccountSetting\StoreLogoRequest;
use App\Http\Resources\AccountSettingResource;
use App\Models\AccountSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BrandingController extends Controller
{
    /**
     * POST /api/admin/account-settings/logo
     */
    public function storeLogo(StoreLogoRequest $request): JsonResponse
    {
        $settings = AccountSetting::current();
        $this->deleteIfExists($settings->logo_path);

        $file = $request->file('file');
        $path = $file->storeAs('account-branding', Str::uuid().'.'.$file->getClientOriginalExtension(), 'public');
        $settings->update(['logo_path' => $path]);

        return response()->json([
            'message' => 'Logo uploaded successfully.',
            'account_settings' => new AccountSettingResource($settings),
        ]);
    }

    /**
     * DELETE /api/admin/account-settings/logo
     */
    public function destroyLogo(): JsonResponse
    {
        $settings = AccountSetting::current();
        $this->deleteIfExists($settings->logo_path);
        $settings->update(['logo_path' => null]);

        return response()->json([
            'message' => 'Logo removed successfully.',
            'account_settings' => new AccountSettingResource($settings),
        ]);
    }

    /**
     * POST /api/admin/account-settings/email-header
     */
    public function storeEmailHeader(StoreEmailHeaderRequest $request): JsonResponse
    {
        $settings = AccountSetting::current();
        $this->deleteIfExists($settings->email_header_path);

        $file = $request->file('file');
        $path = $file->storeAs('account-branding', Str::uuid().'.'.$file->getClientOriginalExtension(), 'public');
        $settings->update(['email_header_path' => $path]);

        return response()->json([
            'message' => 'Email header uploaded successfully.',
            'account_settings' => new AccountSettingResource($settings),
        ]);
    }

    /**
     * DELETE /api/admin/account-settings/email-header
     */
    public function destroyEmailHeader(): JsonResponse
    {
        $settings = AccountSetting::current();
        $this->deleteIfExists($settings->email_header_path);
        $settings->update(['email_header_path' => null]);

        return response()->json([
            'message' => 'Email header removed successfully.',
            'account_settings' => new AccountSettingResource($settings),
        ]);
    }

    private function deleteIfExists(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
