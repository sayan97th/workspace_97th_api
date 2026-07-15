<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkspaceNavigationItemRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:255'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'view_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'href' => ['sometimes', 'nullable', 'string', 'max:255'],
            'display_style' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_favorite' => ['sometimes', 'boolean'],
        ];
    }
}
