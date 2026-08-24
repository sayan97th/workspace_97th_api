<?php

namespace App\Http\Requests\Admin\AccountSetting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdvancedSettingsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'session_inactivity_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'session_max_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
