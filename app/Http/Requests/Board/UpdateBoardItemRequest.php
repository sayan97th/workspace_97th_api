<?php

namespace App\Http\Requests\Board;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoardItemRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'group_id' => [
                'sometimes', 'integer',
                Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
