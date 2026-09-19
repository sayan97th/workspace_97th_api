<?php

namespace App\Http\Requests\Board;

use App\Http\Controllers\Board\BoardItemMoveController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the item drawer's "Move to board" action: the board the item is
 * moved into and the table (group) it lands in. Ownership of the group by
 * the target board, and workspace access to that board, are checked by
 * {@see BoardItemMoveController::store()}, since
 * they need the resolved models rather than raw ids.
 */
class MoveBoardItemToBoardRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'target_board_id' => ['required', 'integer', 'exists:workspace_navigation_items,id'],
            'target_group_id' => ['required', 'integer', 'exists:board_groups,id'],
        ];
    }
}
