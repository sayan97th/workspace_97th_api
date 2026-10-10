<?php

namespace App\Http\Controllers;

use App\Models\AccountSetting;
use App\Support\OrganizationBranding;
use Illuminate\Http\JsonResponse;

/**
 * The organization's branding as the rest of the app sees it. Managed at
 * `/api/admin/organization` (and the older `/admin/account-settings/{logo,email-header}`), but
 * reading it is not staff gated: every signed in user's app shell needs the corner logo, brand
 * color and announcement, and the sign in page needs a safe subset before anyone signs in.
 */
class BrandingController extends Controller
{
    /**
     * GET /api/branding
     */
    public function show(): JsonResponse
    {
        return response()->json(OrganizationBranding::forMembers(AccountSetting::current()));
    }

    /**
     * GET /api/public/branding, no authentication.
     */
    public function showPublic(): JsonResponse
    {
        return response()->json(OrganizationBranding::forSignIn(AccountSetting::current()));
    }
}
