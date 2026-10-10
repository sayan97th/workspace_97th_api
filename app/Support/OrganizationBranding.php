<?php

namespace App\Support;

use App\Models\AccountSetting;

/**
 * The two read only views of the organization settings that leave the Administration area.
 *
 * - {@see self::forMembers()}: what every signed in user's app shell needs (corner logo,
 *   favicon, brand color, Help menu links, announcement banner), served by `GET /api/branding`.
 * - {@see self::forSignIn()}: the small subset safe to show anyone on the sign in page, served
 *   by the unauthenticated `GET /api/public/branding`. Contact details and the internal
 *   announcement are left out on purpose.
 */
class OrganizationBranding
{
    /**
     * @return array<string, mixed>
     */
    public static function forMembers(AccountSetting $settings): array
    {
        return [
            ...self::identity($settings),
            'email_header_url' => $settings->email_header_url,
            'show_name_in_top_bar' => $settings->show_name_in_top_bar,
            'support_email' => $settings->support_email,
            'support_url' => $settings->support_url,
            'social_links' => self::socialLinks($settings),
            'announcement' => $settings->activeAnnouncement(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSignIn(AccountSetting $settings): array
    {
        return [
            ...self::identity($settings),
            'login_headline' => $settings->login_headline,
            'login_message' => $settings->login_message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function identity(AccountSetting $settings): array
    {
        return [
            'company_name' => $settings->account_name,
            'company_tagline' => $settings->company_tagline,
            'company_website' => $settings->company_website,
            'logo_url' => $settings->logo_url,
            'logo_dark_url' => $settings->logo_dark_url,
            'favicon_url' => $settings->favicon_url,
            'brand_color' => $settings->brand_color,
        ];
    }

    /**
     * Every supported network, in a stable order, with null for the ones left empty.
     *
     * @return array<string, string|null>
     */
    public static function socialLinks(AccountSetting $settings): array
    {
        $saved_links = $settings->social_links ?? [];

        return collect(AccountSetting::SOCIAL_NETWORKS)
            ->mapWithKeys(fn (string $network) => [$network => $saved_links[$network] ?? null])
            ->all();
    }
}
