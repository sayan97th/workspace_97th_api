<?php

namespace App\Http\Requests\Board;

use App\Http\Controllers\Board\BoardItemController;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the selection action bar's "Convert" and a subitem selection's
 * "Move to", the bulk counterpart of {@see UpdateBoardItemParentRequest}
 * used by {@see BoardItemController::bulkUpdateParent()}.
 *
 * - `parent_id` set: every selected row is placed under that root item (items
 *   converted into subitems, or subitems moved to another item).
 * - `parent_id` null: every selected subitem becomes an item. `group_id` picks
 *   the table they land in, omitted keeps each one in its parent's own table.
 */
class BulkUpdateBoardItemParentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $board = $this->route('item');
        $board_id = $board instanceof WorkspaceNavigationItem ? $board->id : null;

        return [
            'item_ids' => ['required', 'array', 'min:1', 'max:500'],
            'item_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('board_items', 'id')->where(fn ($query) => $query->where('board_id', $board_id)->whereNull('deleted_at')),
            ],
            'parent_id' => [
                'present', 'nullable', 'integer',
                Rule::exists('board_items', 'id')->where(fn ($query) => $query
                    ->where('board_id', $board_id)
                    ->whereNull('parent_id')
                    ->whereNull('deleted_at')),
            ],
            'group_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
        ];
    }

    /**
     * Same two level rule as the single row request: a row can't hang under
     * itself, and an item that has its own subitems can't become a subitem.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $item_ids = array_map('intval', (array) $this->input('item_ids', []));
            $parent_id = $this->input('parent_id');

            if ($parent_id === null) {
                return;
            }

            if (in_array((int) $parent_id, $item_ids, true)) {
                $validator->errors()->add('parent_id', 'An item cannot become its own parent.');

                return;
            }

            if (BoardItem::whereIn('parent_id', $item_ids)->exists()) {
                $validator->errors()->add('parent_id', 'An item with its own subitems cannot be converted into a subitem.');
            }
        });
    }
}
