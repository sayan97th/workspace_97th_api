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
 * Validates the row menu's "Convert to subitem" / "Convert to item" action —
 * the one boundary {@see BoardItemController::reorder()}
 * deliberately can't cross. `parent_id` set converts a root item into a
 * subitem of that (root) item; `parent_id` null promotes a subitem back to a
 * root item, in which case `group_id` says which table it lands in.
 */
class UpdateBoardItemParentRequest extends FormRequest
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
            'parent_id' => [
                'present', 'nullable', 'integer',
                Rule::exists('board_items', 'id')->where(fn ($query) => $query
                    ->where('board_id', $board_id)
                    ->whereNull('parent_id')),
            ],
            // Deliberately no 'sometimes' here: combined with `Rule::requiredIf`,
            // 'sometimes' would skip the required check entirely whenever the
            // key is absent from the request body — exactly the case this is
            // meant to catch (promoting a subitem to root with no target table).
            'group_id' => [
                Rule::requiredIf(fn () => $this->input('parent_id') === null),
                'nullable', 'integer',
                Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
        ];
    }

    /**
     * Two checks the `exists`/`requiredIf` rules above can't express:
     * - An item can't become its own parent.
     * - An item that already has its own subitems can't be converted into a
     *   subitem itself — the board only supports two levels of nesting
     *   (item -> subitem), so a subitem could never become a parent in turn.
     *   The caller (row menu) must move or delete its subitems first.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $board_item = $this->route('board_item');
            $board_item_id = $board_item instanceof BoardItem ? $board_item->id : null;
            $parent_id = $this->input('parent_id');

            if ($board_item_id === null) {
                return;
            }

            if ((int) $parent_id === (int) $board_item_id) {
                $validator->errors()->add('parent_id', 'An item cannot become its own parent.');

                return;
            }

            if ($parent_id !== null && BoardItem::where('parent_id', $board_item_id)->exists()) {
                $validator->errors()->add('parent_id', 'An item with its own subitems cannot be converted into a subitem.');
            }
        });
    }
}
