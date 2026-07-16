<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBoardGroupRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'accent_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
