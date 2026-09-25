<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/boards/{item}/views/{board_view}/personal-state
 *
 * The viewer's unsaved filter, sort, hidden columns and group by for one view,
 * the same shapes a view saves (see `UpdateBoardViewRequest`).
 */
class UpdateBoardViewPersonalStateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filter_state' => ['present', 'nullable', 'array'],
            'sort_state' => ['present', 'nullable', 'array', 'max:20'],
            'sort_state.*.sort_option_id' => ['nullable', 'string', 'max:64'],
            'sort_state.*.direction' => ['nullable', 'in:asc,desc'],
            'hidden_column_ids' => ['present', 'nullable', 'array', 'max:500'],
            'hidden_column_ids.*' => ['string', 'max:64'],
            'group_by_option_id' => ['present', 'nullable', 'string', 'max:64'],
        ];
    }
}
