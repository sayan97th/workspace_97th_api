<?php

namespace App\Http\Requests\Admin\AccountSetting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileSettingsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_name' => ['sometimes', 'required', 'string', 'max:255'],
            'account_url' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', 'unique:account_settings,account_url,1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_url.regex' => 'The account URL may only contain lowercase letters, numbers, and hyphens.',
        ];
    }
}
