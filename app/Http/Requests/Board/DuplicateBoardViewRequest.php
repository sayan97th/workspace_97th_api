<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/boards/{item}/views/{board_view}/duplicate
 *
 * Every field is optional. With none, it is the tab menu's plain "Duplicate".
 * The toolbar's "Save as new view" also sends a label and the live
 * filter/sort/display state, which is saved on the copy instead of the
 * source tab's own saved state.
 */
class DuplicateBoardViewRequest extends FormRequest
{
    /**
     * State fields that replace the source tab's saved state on the copy.
     *
     * @var array<int, string>
     */
    public const STATE_FIELDS = [
        'filter_state',
        'sort_state',
        'group_by_option_id',
        'hidden_column_ids',
        'pinned_column_ids',
        'row_height',
        'conditional_color_rules',
    ];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:255'],
            'filter_state' => ['sometimes', 'nullable', 'array'],
            'sort_state' => ['sometimes', 'nullable', 'array'],
            'group_by_option_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hidden_column_ids' => ['sometimes', 'nullable', 'array'],
            'pinned_column_ids' => ['sometimes', 'nullable', 'array'],
            'row_height' => ['sometimes', 'string', Rule::in(['single', 'double', 'triple', 'quad'])],
            'conditional_color_rules' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * The state fields actually sent, ready to overwrite the source's saved state.
     *
     * @return array<string, mixed>
     */
    public function stateOverrides(): array
    {
        return array_intersect_key($this->validated(), array_flip(self::STATE_FIELDS));
    }
}
