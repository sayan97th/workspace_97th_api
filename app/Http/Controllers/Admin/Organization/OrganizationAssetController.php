<?php

namespace App\Http\Controllers\Admin\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Organization\StoreOrganizationAssetRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\AccountSetting;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Uploads and removes the organization's branding images: the main logo shown in the top left
 * corner of the app, its dark mode variant and the browser favicon. The `{asset}` segment is
 * limited to the keys of {@see AccountSetting::BRANDING_ASSET_COLUMNS} by the route.
 */
class OrganizationAssetController extends Controller
{
    /**
     * POST /api/admin/organization/assets/{asset}
     */
    public function store(StoreOrganizationAssetRequest $request, string $asset): JsonResponse
    {
        $settings = AccountSetting::current();
        $column = AccountSetting::BRANDING_ASSET_COLUMNS[$asset];
        $this->deleteIfExists($settings->{$column});

        $file = $request->file('file');
        $folder = 'account-branding/'.$settings->id.'/'.str_replace('_', '-', $asset);
        $path = $file->storeAs($folder, Str::uuid().'.'.$file->getClientOriginalExtension(), config('filesystems.app_disk'));
        $settings->update([$column => $path]);

        AuditLogger::log('organization.asset_uploaded', "Uploaded a new organization {$this->assetLabel($asset)}.", $request->user(), ['asset' => $asset]);

        return response()->json([
            'message' => ucfirst($this->assetLabel($asset)).' uploaded successfully.',
            'organization' => new OrganizationResource($settings),
        ]);
    }

    /**
     * DELETE /api/admin/organization/assets/{asset}
     */
    public function destroy(Request $request, string $asset): JsonResponse
    {
        $settings = AccountSetting::current();
        $column = AccountSetting::BRANDING_ASSET_COLUMNS[$asset];
        $this->deleteIfExists($settings->{$column});
        $settings->update([$column => null]);

        AuditLogger::log('organization.asset_removed', "Removed the organization {$this->assetLabel($asset)}.", $request->user(), ['asset' => $asset]);

        return response()->json([
            'message' => ucfirst($this->assetLabel($asset)).' removed successfully.',
            'organization' => new OrganizationResource($settings),
        ]);
    }

    private function assetLabel(string $asset): string
    {
        return match ($asset) {
            'logo_dark' => 'dark mode logo',
            default => $asset,
        };
    }

    private function deleteIfExists(?string $path): void
    {
        $disk = Storage::disk(config('filesystems.app_disk'));

        if ($path && $disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
