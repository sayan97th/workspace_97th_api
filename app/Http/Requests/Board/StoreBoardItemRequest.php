<?php

namespace App\Http\Requests\Board;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBoardItemRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            // Required unless `parent_id` is given — a subitem inherits its
            // group from its parent, so the frontend's "+ Add subitem" call
            // omits `group_id` entirely (see BoardItemController::store()).
            'group_id' => [
                'required_without:parent_id', 'integer',
                Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('board_items', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
            'position' => ['sometimes', 'integer', 'min:0'],
            'values' => ['sometimes', 'array'],
        ];
    }
}
