<?php

namespace App\Http\Requests\Admin\ProfileField;

use App\Models\UserProfileField;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserProfileFieldRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('user_profile_fields', 'name')],
            'type' => ['required', 'string', Rule::in(UserProfileField::TYPES)],
            'options' => ['required_if:type,dropdown', 'nullable', 'array', 'max:50'],
            'options.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'options.*.label' => ['required', 'string', 'max:60'],
            'options.*.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
