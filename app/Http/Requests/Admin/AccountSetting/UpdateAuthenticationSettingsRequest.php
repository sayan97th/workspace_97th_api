<?php

namespace App\Http\Requests\Admin\AccountSetting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAuthenticationSettingsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'two_factor_enforced' => ['sometimes', 'boolean'],
            'google_sso_enabled' => ['sometimes', 'boolean'],
            'saml_sso_enabled' => ['sometimes', 'boolean'],
            'scim_enabled' => ['sometimes', 'boolean'],
            'guest_approval_enabled' => ['sometimes', 'boolean'],
            'approved_domains' => ['sometimes', 'array'],
            'approved_domains.*' => ['string', 'max:255'],
            'ip_restriction_enabled' => ['sometimes', 'boolean'],
            'ip_ranges' => ['sometimes', 'array'],
            'ip_ranges.*' => ['string', 'max:64'],
            'default_product' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
