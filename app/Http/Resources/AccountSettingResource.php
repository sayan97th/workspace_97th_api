<?php

namespace App\Http\Resources;

use App\Models\AccountSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccountSetting
 */
class AccountSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'account_name' => $this->account_name,
            'account_url' => $this->account_url,
            'weekend_start' => $this->weekend_start,
            'show_weekends' => $this->show_weekends,
            'home_page' => $this->home_page,
            'logo_url' => $this->logo_url,
            'email_header_url' => $this->email_header_url,
            'two_factor_enforced' => $this->two_factor_enforced,
            'google_sso_enabled' => $this->google_sso_enabled,
            'saml_sso_enabled' => $this->saml_sso_enabled,
            'scim_enabled' => $this->scim_enabled,
            // Only surfaced to admins/super admins; staff readers only learn whether one exists.
            'scim_token' => $request->user()?->hasRole(['super_admin', 'admin']) ? $this->scim_token : null,
            'scim_token_configured' => $this->scim_token !== null,
            'guest_approval_enabled' => $this->guest_approval_enabled,
            'approved_domains' => $this->approved_domains ?? [],
            'ip_restriction_enabled' => $this->ip_restriction_enabled,
            'ip_ranges' => $this->ip_ranges ?? [],
            'default_product' => $this->default_product,
            'session_inactivity_minutes' => $this->session_inactivity_minutes,
            'session_max_duration_minutes' => $this->session_max_duration_minutes,
            'panic_mode_active' => $this->panic_mode_active,
            'panic_mode_activated_at' => $this->panic_mode_activated_at,
            'panic_mode_activator' => $this->whenLoaded('panicModeActivator', fn () => $this->panicModeActivator ? [
                'id' => $this->panicModeActivator->id,
                'full_name' => $this->panicModeActivator->full_name,
            ] : null),
            'updated_at' => $this->updated_at,
        ];
    }
}
