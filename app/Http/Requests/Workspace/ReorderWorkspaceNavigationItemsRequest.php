<?php

namespace App\Http\Requests\Workspace;

use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a sidebar drag-and-drop reorder (or a "Move up"/"Move down" quick
 * action, which is just a two-item reorder under the hood): the full new
 * sibling order for one parent (`target_ordered_ids` under `target_parent_id`,
 * a folder or `null` for the workspace root), optionally paired with the old
 * parent's remaining order (`source_ordered_ids`/`source_parent_id`) when the
 * dragged item changed folders. Mirrors {@see ReorderBoardItemsRequest}.
 */
class ReorderWorkspaceNavigationItemsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspace = $this->route('workspace');
        $workspace_id = $workspace instanceof Workspace ? $workspace->id : null;

        $belongs_to_workspace = fn ($query) => $query->where('workspace_id', $workspace_id);

        return [
            'moved_item_id' => [
                'required', 'integer',
                Rule::exists('workspace_navigation_items', 'id')->where($belongs_to_workspace),
            ],

            'target_parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('workspace_navigation_items', 'id')->where($belongs_to_workspace),
            ],

            'target_ordered_ids' => ['required', 'array', 'min:1'],
            'target_ordered_ids.*' => [
                'integer', 'distinct',
                Rule::exists('workspace_navigation_items', 'id')->where($belongs_to_workspace),
            ],

            'source_parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('workspace_navigation_items', 'id')->where($belongs_to_workspace),
            ],
            'source_ordered_ids' => ['sometimes', 'array', 'min:1'],
            'source_ordered_ids.*' => [
                'integer', 'distinct',
                Rule::exists('workspace_navigation_items', 'id')->where($belongs_to_workspace),
            ],
        ];
    }

    /**
     * Fine-grained scope checks the coarse `exists` rules above can't express
     * (they'd otherwise reject the dragged item on a cross-folder move, since
     * it hasn't actually moved yet at request time):
     * - `source_parent_id`/`source_ordered_ids` must be given together.
     * - `moved_item_id` must appear exactly once in `target_ordered_ids`.
     * - every other `target_ordered_ids` id must already sit under `target_parent_id`.
     * - every `source_ordered_ids` id must already sit under `source_parent_id`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $has_source_parent = $this->has('source_parent_id');
            $has_source_ids = $this->filled('source_ordered_ids');

            if ($has_source_parent !== $has_source_ids) {
                $validator->errors()->add('source_ordered_ids', 'source_parent_id and source_ordered_ids must be given together.');
            }

            if ($validator->errors()->has('moved_item_id') || $validator->errors()->has('target_ordered_ids')) {
                return;
            }

            $moved_item_id = $this->integer('moved_item_id');
            $target_ids = array_map('intval', (array) $this->input('target_ordered_ids', []));

            if (! in_array($moved_item_id, $target_ids, true)) {
                $validator->errors()->add('target_ordered_ids', 'The moved item must be included in target_ordered_ids.');

                return;
            }

            $target_parent_id = $this->has('target_parent_id') ? $this->input('target_parent_id') : null;
            $target_items = WorkspaceNavigationItem::whereIn('id', $target_ids)->get(['id', 'parent_id'])->keyBy('id');

            foreach ($target_ids as $index => $id) {
                $candidate = $target_items->get($id);
                if (! $candidate) {
                    continue; // Already reported by the `exists` rule above.
                }

                $is_moved_item = $id === $moved_item_id;
                if (! $is_moved_item && $candidate->parent_id !== $target_parent_id) {
                    $validator->errors()->add("target_ordered_ids.{$index}", 'This item does not belong to the target parent.');
                }
            }

            if ($has_source_ids && ! $validator->errors()->has('source_ordered_ids')) {
                $source_parent_id = $this->input('source_parent_id');
                $source_ids = array_map('intval', (array) $this->input('source_ordered_ids', []));
                $source_items = WorkspaceNavigationItem::whereIn('id', $source_ids)->get(['id', 'parent_id'])->keyBy('id');

                foreach ($source_ids as $index => $id) {
                    $candidate = $source_items->get($id);
                    if (! $candidate) {
                        continue;
                    }
                    if ($candidate->parent_id !== $source_parent_id) {
                        $validator->errors()->add("source_ordered_ids.{$index}", 'This item does not belong to the source parent.');
                    }
                }
            }
        });
    }
}
