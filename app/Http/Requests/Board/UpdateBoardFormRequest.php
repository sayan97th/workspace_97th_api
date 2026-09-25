<?php

namespace App\Http\Requests\Board;

use App\Services\Board\BoardFormService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Form view builder's settings. Question columns are checked against the
 * source tab in {@see BoardFormService}.
 */
class UpdateBoardFormRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source_view_id' => ['sometimes', 'integer'],
            'target_group_id' => ['sometimes', 'nullable', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'name_label' => ['sometimes', 'string', 'max:255'],
            'submit_label' => ['sometimes', 'string', 'max:60'],
            'success_message' => ['sometimes', 'string', 'max:1000'],
            'accent_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'questions' => ['sometimes', 'array', 'max:100'],
            'questions.*.column_id' => ['required', 'integer', 'distinct'],
            'questions.*.label' => ['nullable', 'string', 'max:255'],
            'questions.*.description' => ['nullable', 'string', 'max:1000'],
            'questions.*.is_required' => ['sometimes', 'boolean'],
            'questions.*.is_visible' => ['sometimes', 'boolean'],
        ];
    }
}
