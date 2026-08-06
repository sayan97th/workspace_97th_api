<?php

namespace App\Http\Requests\Board;

use App\Enums\BoardViewType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBoardViewRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            // Which content the tab renders — immutable once created (see App\Enums\BoardViewType), defaults to 'table'.
            'view_type' => ['sometimes', 'string', Rule::in(BoardViewType::values())],
            'icon' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_primary' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'filter_state' => ['sometimes', 'nullable', 'array'],
            'sort_state' => ['sometimes', 'nullable', 'array'],
            'group_by_option_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hidden_column_ids' => ['sometimes', 'nullable', 'array'],
            'pinned_column_ids' => ['sometimes', 'nullable', 'array'],
            'row_height' => ['sometimes', 'string', Rule::in(['single', 'double', 'triple'])],
            'conditional_color_rules' => ['sometimes', 'nullable', 'array'],
            'doc_content' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
