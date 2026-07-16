<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoardViewRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Every field is optional — this is also the "save filters for this
     * board view" endpoint, called with just the subset of state that
     * changed (e.g. only `filter_state` after tweaking Advanced Filters).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'filter_state' => ['sometimes', 'nullable', 'array'],
            'sort_state' => ['sometimes', 'nullable', 'array'],
            'group_by_option_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hidden_column_ids' => ['sometimes', 'nullable', 'array'],
            'pinned_column_ids' => ['sometimes', 'nullable', 'array'],
            'row_height' => ['sometimes', 'string', Rule::in(['single', 'double', 'triple'])],
            'conditional_color_rules' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
