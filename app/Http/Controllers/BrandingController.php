<?php

namespace App\Http\Controllers;

use App\Models\AccountSetting;
use Illuminate\Http\JsonResponse;

/**
 * The account's public-facing branding (logo, email header) — unlike
 * `Admin\AccountSetting\BrandingController` (which manages it), reading these two URLs is
 * not staff-gated: every signed-in user's top bar needs the account logo, not just admins.
 */
class BrandingController extends Controller
{
    /**
     * GET /api/branding
     */
    public function show(): JsonResponse
    {
        $settings = AccountSetting::current();

        return response()->json([
            'logo_url' => $settings->logo_url,
            'email_header_url' => $settings->email_header_url,
        ]);
    }
}
