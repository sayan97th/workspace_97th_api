<?php

namespace App\Http\Requests\Board;

use App\Models\BoardColumn;
use App\Models\WorkspaceNavigationItem;
use App\Rules\ValidFormulaExpression;
use App\Services\Board\ConnectBoardColumnService;
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
            'type' => ['required', 'string', Rule::in(BoardColumn::TYPES)],
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
            // Formula/Connect-board/Mirror columns only, all optional even for
            // their own type: a fresh column from the "+" gallery is created
            // unconfigured (like Status/Label start with a default option set
            // but Formula/Mirror have no sensible default), then configured
            // afterward through the header menu's "Configure ..." modal (see
            // `BoardColumnController::update()`), not at creation time.
            // Formula columns: the saved expression, columns referenced by id like `{#12}`. `operation`/`source_column_ids` are the legacy fixed-operation form, still accepted.
            'config.expression' => ['sometimes', 'string', 'min:1', 'max:2000', new ValidFormulaExpression($view_id, $scope)],
            'config.operation' => ['sometimes', 'string', Rule::in(['sum', 'subtract', 'multiply', 'divide', 'concat'])],
            'config.source_column_ids' => ['sometimes', 'array', 'min:1'],
            'config.source_column_ids.*' => ['integer', Rule::exists('board_columns', 'id')],
            // Button columns only: the text on the button and its color.
            'config.button_label' => ['sometimes', 'string', 'max:40'],
            'config.button_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'config.linked_board_id' => ['sometimes', 'integer', Rule::exists('workspace_navigation_items', 'id')],
            // Connect-board columns only: every connected board (`linked_board_id` is the first one), whether a cell may hold several items,
            // and the two-way link, all saved through the column's own "connect-settings" endpoint. Kept here so other config saves keep them.
            'config.linked_board_ids' => ['sometimes', 'array', 'max:'.ConnectBoardColumnService::MAX_LINKED_BOARDS],
            'config.linked_board_ids.*' => ['integer', Rule::exists('workspace_navigation_items', 'id')],
            'config.allow_multiple_items' => ['sometimes', 'boolean'],
            'config.two_way' => ['sometimes', 'boolean'],
            'config.reverse_column_id' => ['sometimes', 'integer', Rule::exists('board_columns', 'id')->where(fn ($query) => $query->where('type', BoardColumn::TYPE_CONNECT_BOARD))],
            'config.source_column_id' => ['sometimes', 'integer', Rule::exists('board_columns', 'id')->where(fn ($query) => $query->where('type', BoardColumn::TYPE_CONNECT_BOARD))],
            'config.mirrored_column_id' => ['sometimes', 'integer', Rule::exists('board_columns', 'id')],
            // Validation rules, settable on any column kind from the header
            // menu's "Settings" panel — advisory only (flags an empty/out-of-
            // range/mismatched cell for the user), never blocks a cell save.
            // Any column: the description the header's info popover shows ("Column description").
            // Hour columns only: 12 hour ("2:30 PM") or 24 hour ("14:30") display.
            'config.hour_format' => ['sometimes', Rule::in(['12', '24'])],
            'config.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'config.validation' => ['sometimes', 'nullable', 'array'],
            'config.validation.required' => ['sometimes', 'boolean'],
            'config.validation.min' => ['sometimes', 'nullable', 'numeric'],
            'config.validation.max' => ['sometimes', 'nullable', 'numeric'],
            'config.validation.pattern' => ['sometimes', 'nullable', 'string', 'max:500'],
            'hideable' => ['sometimes', 'boolean'],
            'pinnable' => ['sometimes', 'boolean'],
        ];
    }
}
