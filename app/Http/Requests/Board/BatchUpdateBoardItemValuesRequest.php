<?php

namespace App\Http\Requests\Board;

use App\Http\Controllers\Board\BoardItemController;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the table's multi-cell writes (a range paste, a fill handle drag,
 * clearing a selected range): several items, each with its own
 * `{column_id: value}` map. Values stay loosely validated, like
 * {@see UpdateBoardItemValuesRequest}, since their shape depends on the
 * column kind. See {@see BoardItemController::batchUpdateValues()}.
 */
class BatchUpdateBoardItemValuesRequest extends FormRequest
{
    /** Upper bound for one request, a paste of 500 rows by 10 columns. */
    public const MAX_ITEMS = 500;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $board = $this->route('item');
        $board_id = $board instanceof WorkspaceNavigationItem ? $board->id : null;

        return [
            'cells' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'cells.*.item_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('board_items', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
            'cells.*.values' => ['required', 'array', 'min:1', 'max:50'],
        ];
    }
}
