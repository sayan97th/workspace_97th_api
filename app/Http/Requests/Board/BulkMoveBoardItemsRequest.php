<?php

namespace App\Http\Requests\Board;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the selection action bar's "Move to" bulk action: the given
 * items, plus the target group (table) they're all moved into.
 */
class BulkMoveBoardItemsRequest extends FormRequest
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
            'group_id' => [
                'required', 'integer',
                Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
        ];
    }
}
