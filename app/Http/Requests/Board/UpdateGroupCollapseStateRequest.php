<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGroupCollapseStateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'view_id' => ['nullable', 'integer', 'exists:board_views,id'],
            'collapsed_group_ids' => ['present', 'array'],
            'collapsed_group_ids.*' => ['integer'],
        ];
    }
}
