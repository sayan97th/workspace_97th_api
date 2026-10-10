<?php

namespace App\Http\Requests\Admin\Organization;

use App\Models\AccountSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    /**
     * Normalizes the values people type in different ways, so the stored data stays uniform.
     */
    protected function prepareForValidation(): void
    {
        $normalized_values = [];

        if (is_string($this->input('brand_color'))) {
            $normalized_values['brand_color'] = strtolower(trim($this->input('brand_color')));
        }

        if (is_string($this->input('address_country'))) {
            $normalized_values['address_country'] = strtoupper(trim($this->input('address_country')));
        }

        if (is_string($this->input('support_email'))) {
            $normalized_values['support_email'] = strtolower(trim($this->input('support_email')));
        }

        $this->merge($normalized_values);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $web_url = ['nullable', 'string', 'max:255', 'url:http,https'];

        return [
            'company_name' => ['sometimes', 'required', 'string', 'max:255'],
            'company_legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company_tagline' => ['sometimes', 'nullable', 'string', 'max:120'],
            'company_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'company_industry' => ['sometimes', 'nullable', 'string', 'max:64'],
            'company_size' => ['sometimes', 'nullable', Rule::in(AccountSetting::COMPANY_SIZES)],
            'company_founded_year' => ['sometimes', 'nullable', 'integer', 'min:1800', 'max:'.now()->year],
            'company_website' => ['sometimes', ...$web_url],

            'support_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'support_url' => ['sometimes', ...$web_url],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[0-9+().\-\s]{5,40}$/'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address_state' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address_postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address_country' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],

            'social_links' => ['sometimes', 'nullable', 'array:'.implode(',', AccountSetting::SOCIAL_NETWORKS)],
            'social_links.*' => $web_url,

            'brand_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-f]{6}$/'],
            'show_name_in_top_bar' => ['sometimes', 'boolean'],

            'login_headline' => ['sometimes', 'nullable', 'string', 'max:120'],
            'login_message' => ['sometimes', 'nullable', 'string', 'max:280'],

            'announcement_enabled' => ['sometimes', 'boolean'],
            'announcement_message' => ['sometimes', 'nullable', 'string', 'max:280', 'required_if_accepted:announcement_enabled'],
            'announcement_tone' => ['sometimes', 'required', Rule::in(AccountSetting::ANNOUNCEMENT_TONES)],
            'announcement_link_label' => ['sometimes', 'nullable', 'string', 'max:40', 'required_with:announcement_link_url'],
            'announcement_link_url' => ['sometimes', ...$web_url],
            'announcement_dismissible' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'brand_color.regex' => 'The brand color must be a hex color like #e53e2e.',
            'address_country.regex' => 'Pick a country from the list.',
            'contact_phone.regex' => 'The phone number may only contain digits, spaces and + ( ) . - characters.',
            'social_links.array' => 'Only LinkedIn, X, Facebook, Instagram and YouTube links are supported.',
            'social_links.*.url' => 'Each social profile must be a full link starting with https://.',
            'announcement_message.required_if_accepted' => 'Write a message before turning the announcement on.',
            'announcement_link_label.required_with' => 'Give the announcement link a label.',
        ];
    }
}
