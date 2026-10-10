<?php

namespace App\Http\Resources;

use App\Models\AccountSetting;
use App\Support\OrganizationBranding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Administration view of the organization settings (`/admin/organization`), every field the
 * Organization page can edit plus the uploaded asset URLs.
 *
 * @mixin AccountSetting
 */
class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'company_name' => $this->account_name,
            'account_url' => $this->account_url,
            'company_legal_name' => $this->company_legal_name,
            'company_tagline' => $this->company_tagline,
            'company_description' => $this->company_description,
            'company_industry' => $this->company_industry,
            'company_size' => $this->company_size,
            'company_founded_year' => $this->company_founded_year,
            'company_website' => $this->company_website,
            'support_email' => $this->support_email,
            'support_url' => $this->support_url,
            'contact_phone' => $this->contact_phone,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'address_city' => $this->address_city,
            'address_state' => $this->address_state,
            'address_postal_code' => $this->address_postal_code,
            'address_country' => $this->address_country,
            'social_links' => OrganizationBranding::socialLinks($this->resource),
            'logo_url' => $this->logo_url,
            'logo_dark_url' => $this->logo_dark_url,
            'favicon_url' => $this->favicon_url,
            'brand_color' => $this->brand_color,
            'show_name_in_top_bar' => $this->show_name_in_top_bar,
            'login_headline' => $this->login_headline,
            'login_message' => $this->login_message,
            'announcement_enabled' => $this->announcement_enabled,
            'announcement_message' => $this->announcement_message,
            'announcement_tone' => $this->announcement_tone,
            'announcement_link_label' => $this->announcement_link_label,
            'announcement_link_url' => $this->announcement_link_url,
            'announcement_dismissible' => $this->announcement_dismissible,
            'announcement_published_at' => $this->announcement_published_at,
            'can_edit' => (bool) $request->user()?->hasRole(['super_admin', 'admin']),
            'updated_at' => $this->updated_at,
        ];
    }
}
