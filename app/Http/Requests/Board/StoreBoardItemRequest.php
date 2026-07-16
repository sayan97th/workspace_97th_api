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
            'group_id' => [
                'required', 'integer',
                Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
            'position' => ['sometimes', 'integer', 'min:0'],
            'values' => ['sometimes', 'array'],
        ];
    }
}
