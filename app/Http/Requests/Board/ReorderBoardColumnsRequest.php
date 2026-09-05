<?php

namespace App\Http\Requests\Board;

use App\Models\BoardColumn;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a column-header drag-and-drop reorder: the full new column id
 * order for one scope (main table header or subitem header), optionally
 * scoped to a specific tab (`view_id`, defaulting to the board's primary tab
 * server-side, mirroring every other per-tab column endpoint). Every id only
 * needs to belong to this board here — {@see BoardColumnController::reorder()}
 * further narrows to the resolved view+scope and silently drops anything
 * that doesn't actually belong there, the same tolerant pattern
 * `BoardGroupController::updateCollapsedState()` uses, so a stale client
 * list can't reject the whole drag.
 */
class ReorderBoardColumnsRequest extends FormRequest
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
            'scope' => ['required', Rule::in([BoardColumn::SCOPE_ITEM, BoardColumn::SCOPE_SUBITEM])],
            'view_id' => ['sometimes', 'integer'],

            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => [
                'integer', 'distinct',
                Rule::exists('board_columns', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
        ];
    }
}
