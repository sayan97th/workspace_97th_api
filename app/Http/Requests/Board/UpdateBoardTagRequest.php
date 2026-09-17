<?php

namespace App\Http\Requests\Board;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoardTagRequest extends FormRequest
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
        $tag = $this->route('tag');
        $tag_id = is_object($tag) ? $tag->id : null;

        return [
            'label' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('board_tags', 'label')
                    ->where(fn ($query) => $query->where('board_id', $board_id))
                    ->ignore($tag_id),
            ],
            'color' => ['sometimes', 'string', 'max:20'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
