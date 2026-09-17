<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the column menu's "Duplicate column" action.
 */
class DuplicateBoardColumnRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'with_values' => ['sometimes', 'boolean'],
            /** Present only for the column menu's "Duplicate to another board" — see `BoardColumnController::duplicate()`. */
            'target_board_id' => ['sometimes', 'nullable', 'integer', 'exists:workspace_navigation_items,id'],
            /** Optional tab on the target board, defaults to its primary tab when omitted. Ignored unless `target_board_id` is present. */
            'target_view_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
