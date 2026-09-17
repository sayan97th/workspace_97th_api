<?php

namespace App\Http\Requests\Board;

use App\Models\BoardColumn;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoardColumnRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in([
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
                BoardColumn::TYPE_RATING,
                BoardColumn::TYPE_VOTE,
                BoardColumn::TYPE_LINK,
                BoardColumn::TYPE_FILES,
                BoardColumn::TYPE_TIME_TRACKING,
                BoardColumn::TYPE_AUTO_NUMBER,
                BoardColumn::TYPE_FORMULA,
                BoardColumn::TYPE_CONNECT_BOARD,
                BoardColumn::TYPE_MIRROR,
                BoardColumn::TYPE_CHECKLIST,
            ])],
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
            // Formula columns only: the operation applied to `source_column_ids`, in row order.
            'config.operation' => ['sometimes', 'string', Rule::in(['sum', 'subtract', 'multiply', 'divide', 'concat'])],
            'config.source_column_ids' => ['sometimes', 'array', 'min:1'],
            'config.source_column_ids.*' => ['integer', Rule::exists('board_columns', 'id')],
            // Connect-board columns only: the other board this column's cells link items on.
            'config.linked_board_id' => ['sometimes', 'integer', Rule::exists('workspace_navigation_items', 'id')],
            // Mirror columns only: which of this tab's own connect-board columns to read through, and which column on that linked board to display.
            'config.source_column_id' => ['sometimes', 'integer', Rule::exists('board_columns', 'id')->where(fn ($query) => $query->where('type', BoardColumn::TYPE_CONNECT_BOARD))],
            'config.mirrored_column_id' => ['sometimes', 'integer', Rule::exists('board_columns', 'id')],
            // Validation rules, settable on any column kind — see `StoreBoardColumnRequest`'s own comment.
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
