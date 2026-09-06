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

        // `key` uniqueness is per-tab, not per-board — resolve which tab this
        // request targets the same way `BoardViewResolver::resolveForWrite`
        // does (explicit `view_id`, falling back to the board's primary tab),
        // so the uniqueness check lines up with where the column actually lands.
        $view_id = $this->input('view_id')
            ?? WorkspaceNavigationItem::find($board_id)?->views()->where('is_primary', true)->value('id');
        $scope = $this->input('scope', BoardColumn::SCOPE_ITEM);

        return [
            'view_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('board_views', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
            'scope' => ['sometimes', 'string', Rule::in([BoardColumn::SCOPE_ITEM, BoardColumn::SCOPE_SUBITEM])],
            'key' => [
                'required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('board_columns', 'key')->where(fn ($query) => $query
                    ->where('board_view_id', $view_id)
                    ->where('scope', $scope)),
            ],
            'label' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in([
                BoardColumn::TYPE_TEXT,
                BoardColumn::TYPE_STATUS,
                BoardColumn::TYPE_PEOPLE,
                BoardColumn::TYPE_DATE,
                BoardColumn::TYPE_TAGS,
                BoardColumn::TYPE_DROPDOWN,
                BoardColumn::TYPE_NUMBER,
                BoardColumn::TYPE_CHECKBOX,
                BoardColumn::TYPE_TIMELINE,
                BoardColumn::TYPE_DEPENDENCY,
                BoardColumn::TYPE_LABEL,
                BoardColumn::TYPE_PROGRESS,
                BoardColumn::TYPE_LONG_TEXT,
                BoardColumn::TYPE_PHONE,
                BoardColumn::TYPE_EMAIL,
            ])],
            'position' => ['sometimes', 'integer', 'min:0'],
            'width' => ['sometimes', 'integer', 'min:40', 'max:600'],
            'config' => ['sometimes', 'nullable', 'array'],
            'config.options' => ['sometimes', 'array'],
            'config.options.*.id' => ['required', 'string', 'max:100'],
            'config.options.*.label' => ['required', 'string', 'max:255'],
            'config.options.*.color' => ['required', 'string', 'max:20'],
            'config.options.*.is_active' => ['sometimes', 'boolean'],
            'config.options.*.description' => ['sometimes', 'nullable', 'string', 'max:500'],
            // People columns only: whether assigning someone here notifies
            // them (in-app + email) — the People cell picker's bottom toggle.
            'config.notify_on_assignment' => ['sometimes', 'boolean'],
            'hideable' => ['sometimes', 'boolean'],
            'pinnable' => ['sometimes', 'boolean'],
        ];
    }
}
