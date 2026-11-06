<?php

namespace App\Http\Requests\Board;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnalyzeBoardImportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $board = $this->route('item');
        $board_id = $board instanceof WorkspaceNavigationItem ? $board->id : null;

        return [
            // Pictures and documents embedded in a workbook make it far heavier than its text.
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:51200'],
            'sheet_index' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255'],
            'discard_token' => ['sometimes', 'nullable', 'string', 'size:36'],
            'view_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('board_views', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
        ];
    }
}
