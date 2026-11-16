<?php

namespace App\Http\Requests\Board;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a bulk cell edit: the given items, plus the one column changed on
 * every one of them. Either `value` replaces each item's cell (same shape as
 * {@see UpdateBoardItemValuesRequest}, resolved per item by
 * {@see BoardItemValueService::sync()}), or `added` and `removed` merge into
 * each item's own list, so editing a People, Dropdown, Tags or Connect boards
 * cell on several checked rows adds or removes the same entries everywhere
 * without wiping what each row already had, like monday.com.
 */
class BulkSetColumnValueRequest extends FormRequest
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
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => [
                'integer',
                Rule::exists('board_items', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
            'column_id' => ['required', 'integer'],
            'value' => ['nullable'],
            'added' => ['sometimes', 'array'],
            'added.*' => ['required'],
            'removed' => ['sometimes', 'array'],
            'removed.*' => ['required'],
        ];
    }

    /**
     * A request must say how to change the cells: a `value` to write, or entries to add or remove.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $has_value = $this->exists('value');
            $has_merge = $this->exists('added') || $this->exists('removed');

            if (! $has_value && ! $has_merge) {
                $validator->errors()->add('value', 'Send a value, or the entries to add or remove.');
            }

            if ($has_value && $has_merge) {
                $validator->errors()->add('value', 'Send either a value or the entries to add or remove, not both.');
            }
        });
    }

    /**
     * Whether the cells merge `added` and `removed` into their own lists instead of taking `value`.
     */
    public function isMerge(): bool
    {
        return ! $this->exists('value');
    }
}
