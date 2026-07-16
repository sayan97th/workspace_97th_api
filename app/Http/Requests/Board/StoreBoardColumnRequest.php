<?php

namespace App\Http\Requests\Board;

use App\Models\BoardColumn;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBoardColumnRequest extends FormRequest
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
            'key' => [
                'required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('board_columns', 'key')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
            'label' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in([
                BoardColumn::TYPE_TEXT,
                BoardColumn::TYPE_STATUS,
                BoardColumn::TYPE_PEOPLE,
                BoardColumn::TYPE_DATE,
                BoardColumn::TYPE_TAGS,
                BoardColumn::TYPE_NUMBER,
                BoardColumn::TYPE_CHECKBOX,
            ])],
            'position' => ['sometimes', 'integer', 'min:0'],
            'width' => ['sometimes', 'integer', 'min:40', 'max:600'],
            'config' => ['sometimes', 'nullable', 'array'],
            'hideable' => ['sometimes', 'boolean'],
            'pinnable' => ['sometimes', 'boolean'],
        ];
    }
}
