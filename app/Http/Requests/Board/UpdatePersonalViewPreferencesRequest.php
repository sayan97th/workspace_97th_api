<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonalViewPreferencesRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Both fields are optional, so the client only sends what changed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hidden_view_ids' => ['sometimes', 'array'],
            'hidden_view_ids.*' => ['integer'],
            'default_view_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
