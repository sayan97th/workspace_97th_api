<?php

namespace App\Http\Requests\Admin\ProfileField;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A field's type is fixed once created (existing values were validated against it), so
 * only its name and, for dropdowns, its options can change.
 */
class UpdateUserProfileFieldRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:80', Rule::unique('user_profile_fields', 'name')->ignore($this->route('field'))],
            'options' => ['sometimes', 'nullable', 'array', 'max:50'],
            'options.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'options.*.label' => ['required', 'string', 'max:60'],
            'options.*.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
