<?php

namespace App\Http\Requests\Board;

use App\Http\Controllers\Board\BoardItemController;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a drag-and-drop reorder: either root items within a table (and
 * optionally moved into a *different* table), or subitems within their
 * shared parent. `target_ordered_ids`/`source_ordered_ids` are each checked
 * (in {@see withValidator()}) to already belong to the exact list they claim
 * — a group for root items, a parent for subitems — with the single
 * exception of the dragged item itself on a cross-group move (it's still in
 * its *old* group at request time, that's precisely what's changing). That
 * scoping is also what keeps this endpoint from being usable to promote a
 * subitem to root (or vice versa): only `group_id` is ever written by
 * {@see BoardItemController::reorder()}, never
 * `parent_id`.
 */
class ReorderBoardItemsRequest extends FormRequest
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
        $scope = $this->input('scope');

        return [
            'scope' => ['required', Rule::in(['root', 'subitem'])],

            'moved_item_id' => [
                Rule::requiredIf($scope === 'root'),
                'integer',
                Rule::exists('board_items', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],

            'target_group_id' => [
                Rule::requiredIf($scope === 'root'),
                'integer',
                Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],

            'target_parent_id' => [
                Rule::requiredIf($scope === 'subitem'),
                'integer',
                Rule::exists('board_items', 'id')->where(fn ($query) => $query
                    ->where('board_id', $board_id)
                    ->whereNull('parent_id')),
            ],

            'target_ordered_ids' => ['required', 'array', 'min:1'],
            'target_ordered_ids.*' => [
                'integer', 'distinct',
                Rule::exists('board_items', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],

            'source_group_id' => [
                'sometimes', 'integer',
                Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
            'source_ordered_ids' => ['sometimes', 'array', 'min:1'],
            'source_ordered_ids.*' => [
                'integer', 'distinct',
                Rule::exists('board_items', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
        ];
    }

    /**
     * Fine-grained scope checks that the coarse `exists` rules above can't
     * express (they'd otherwise reject the dragged item on a cross-group
     * move, since it hasn't actually moved yet at request time):
     * - `source_group_id`/`source_ordered_ids` must be given together.
     * - every `target_ordered_ids` id must already sit in `target_group_id`
     *   (root scope) or under `target_parent_id` (subitem scope) — except
     *   `moved_item_id` itself, which is exempt.
     * - every `source_ordered_ids` id must already sit in `source_group_id`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $has_source_group = $this->filled('source_group_id');
            $has_source_ids = $this->filled('source_ordered_ids');

            if ($has_source_group !== $has_source_ids) {
                $validator->errors()->add('source_ordered_ids', 'source_group_id and source_ordered_ids must be given together.');
            }

            if ($validator->errors()->has('scope') || $validator->errors()->has('target_ordered_ids')) {
                return;
            }

            $scope = $this->input('scope');
            $moved_item_id = $this->integer('moved_item_id');
            $target_ids = array_map('intval', (array) $this->input('target_ordered_ids', []));

            $target_items = BoardItem::whereIn('id', $target_ids)->get(['id', 'group_id', 'parent_id'])->keyBy('id');

            foreach ($target_ids as $index => $id) {
                $candidate = $target_items->get($id);
                if (! $candidate) {
                    continue; // Already reported by the `exists` rule above.
                }

                if ($scope === 'subitem') {
                    if ((int) $candidate->parent_id !== $this->integer('target_parent_id')) {
                        $validator->errors()->add("target_ordered_ids.{$index}", 'This item does not belong to the target parent.');
                    }
                } else {
                    $is_moved_item = $id === $moved_item_id;
                    if ($candidate->parent_id !== null || (! $is_moved_item && (int) $candidate->group_id !== $this->integer('target_group_id'))) {
                        $validator->errors()->add("target_ordered_ids.{$index}", 'This item does not belong to the target table.');
                    }
                }
            }

            if ($has_source_ids && ! $validator->errors()->has('source_ordered_ids')) {
                $source_ids = array_map('intval', (array) $this->input('source_ordered_ids', []));
                $source_items = BoardItem::whereIn('id', $source_ids)->get(['id', 'group_id', 'parent_id'])->keyBy('id');

                foreach ($source_ids as $index => $id) {
                    $candidate = $source_items->get($id);
                    if (! $candidate) {
                        continue;
                    }
                    if ($candidate->parent_id !== null || (int) $candidate->group_id !== $this->integer('source_group_id')) {
                        $validator->errors()->add("source_ordered_ids.{$index}", 'This item does not belong to the source table.');
                    }
                }
            }
        });
    }
}
