<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkspaceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'mono' => ['sometimes', 'nullable', 'string', 'max:2'],
            'color' => ['sometimes', 'nullable', 'string', 'max:9'],
            'product' => ['sometimes', 'nullable', 'string', 'max:255'],
            'privacy' => ['sometimes', 'required', 'string', 'in:open,closed'],
        ];
    }
}
