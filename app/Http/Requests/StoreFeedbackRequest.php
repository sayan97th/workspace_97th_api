<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFeedbackRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:3', 'max:4000'],
            'board_id' => ['sometimes', 'nullable', 'integer', 'exists:workspace_navigation_items,id'],
            'page_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }
}
