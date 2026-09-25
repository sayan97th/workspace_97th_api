<?php

namespace App\Http\Requests\Admin\ProfileField;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderUserProfileFieldsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'field_ids' => ['required', 'array', 'min:1'],
            'field_ids.*' => ['integer', 'distinct', 'exists:user_profile_fields,id'],
        ];
    }
}
