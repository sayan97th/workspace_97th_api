<?php

namespace App\Http\Controllers\Admin\Organization;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HomeBanner\HomeBannerController;
use App\Http\Requests\HomeBanner\StoreHomeBannerRequest;
use App\Http\Requests\HomeBanner\UpdateHomeBannerRequest;
use App\Models\HomeBanner;
use App\Models\User;
use App\Services\HomeBanner\HomeBannerService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration > Organization > Home banner: the background image everyone in the organization
 * sees on Home unless they picked their own. Admins only, enforced by the route.
 */
class OrganizationHomeBannerController extends Controller
{
    public function __construct(private readonly HomeBannerService $banners) {}

    /**
     * POST /api/admin/organization/home-banner
     */
    public function store(StoreHomeBannerRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->banners->store(null, $request->file('file'), $request->framing(), $user, $request->sourceDetails());

        AuditLogger::log('organization.home_banner_uploaded', 'Uploaded a new Home banner for the organization.', $user);

        return response()->json([
            'message' => 'The organization banner was updated.',
            ...HomeBannerController::payload($user),
        ]);
    }

    /**
     * PATCH /api/admin/organization/home-banner
     */
    public function update(UpdateHomeBannerRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $banner = HomeBanner::organizationBanner();
        abort_if($banner === null, 404, 'The organization has no banner yet.');

        $this->banners->reframe($banner, $request->framing(), $user);

        AuditLogger::log('organization.home_banner_reframed', 'Adjusted the organization Home banner.', $user, [
            'fields' => array_keys($request->framing()),
        ]);

        return response()->json([
            'message' => 'The organization banner was updated.',
            ...HomeBannerController::payload($user),
        ]);
    }

    /**
     * DELETE /api/admin/organization/home-banner
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $banner = HomeBanner::organizationBanner();
        if ($banner) {
            $this->banners->destroy($banner);
            AuditLogger::log('organization.home_banner_removed', 'Removed the organization Home banner.', $user);
        }

        return response()->json([
            'message' => 'The organization banner was removed.',
            ...HomeBannerController::payload($user),
        ]);
    }
}
