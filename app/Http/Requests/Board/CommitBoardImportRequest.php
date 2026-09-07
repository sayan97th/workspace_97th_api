<?php

namespace App\Http\Requests\Board;

use App\Models\BoardColumn;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommitBoardImportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $board = $this->route('item');
        $board_id = $board instanceof WorkspaceNavigationItem ? $board->id : null;

        // Mirrors `StoreBoardColumnRequest`'s own `view_id` resolution — the
        // explicit id if given, otherwise whichever tab is primary — so
        // `target_group_id`'s own scoping check below lines up with the tab
        // the import actually lands on.
        $view_id = $this->input('view_id')
            ?? WorkspaceNavigationItem::find($board_id)?->views()->where('is_primary', true)->value('id');

        return [
            'import_token' => ['required', 'string', 'size:36'],
            'view_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('board_views', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
            'target_group_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id)),
            ],
            'new_group_name' => ['sometimes', 'nullable', 'string', 'max:255'],

            'mappings' => ['required', 'array', 'min:1'],
            'mappings.*.source_index' => ['required', 'integer', 'min:0'],
            'mappings.*.mode' => ['required', 'string', Rule::in(['name', 'map', 'create', 'skip'])],
            'mappings.*.target_column_id' => ['sometimes', 'nullable', 'integer'],
            'mappings.*.new_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mappings.*.new_type' => ['sometimes', 'nullable', 'string', Rule::in([
                BoardColumn::TYPE_TEXT,
                BoardColumn::TYPE_LONG_TEXT,
                BoardColumn::TYPE_STATUS,
                BoardColumn::TYPE_LABEL,
                BoardColumn::TYPE_PEOPLE,
                BoardColumn::TYPE_DATE,
                BoardColumn::TYPE_TAGS,
                BoardColumn::TYPE_DROPDOWN,
                BoardColumn::TYPE_NUMBER,
                BoardColumn::TYPE_CHECKBOX,
                BoardColumn::TYPE_PROGRESS,
                BoardColumn::TYPE_PHONE,
                BoardColumn::TYPE_EMAIL,
            ])],

            'duplicate_mode' => ['required', 'string', Rule::in(['add', 'skip', 'update'])],
            'match_source_index' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
