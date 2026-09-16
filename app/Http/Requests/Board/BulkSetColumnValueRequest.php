<?php

namespace App\Http\Requests\Board;

use App\Http\Controllers\Board\BoardItemController;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the selection action bar's "Edit column" bulk action: the given
 * items, plus the one column and value applied to every one of them. `value`'s
 * shape is column-kind-dependent, same as {@see UpdateBoardItemValuesRequest},
 * so it's left unvalidated here and resolved per item by
 * {@see BoardItemController::syncValues()}.
 */
class BulkSetColumnValueRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $board = $this->route('item');
        $board_id = $board instanceof WorkspaceNavigationItem ? $board->id : null;

        return [
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => [
                'integer',
                Rule::exists('board_items', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
            'column_id' => ['required', 'integer'],
            'value' => ['present'],
        ];
    }
}
