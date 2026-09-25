<?php

namespace App\Services\Board;

use App\Http\Controllers\Board\BoardViewController;
use App\Http\Controllers\Workspace\WorkspaceNavigationItemController;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardItemValue;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use App\Support\BoardViewConfigRemapper;
use App\Support\FormulaReferences;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deep-copies a board view (tab) — its columns, groups, items and cell
 * values, plus its saved filter/sort/display state remapped onto the freshly
 * cloned columns — into a target board. The target is usually the same
 * board the source view belongs to ({@see BoardViewController::duplicate()},
 * "Duplicate this view"), but is a brand-new board when the board options
 * menu's "Duplicate board" duplicates every one of a board's views in turn
 * (see {@see WorkspaceNavigationItemController::duplicate()}).
 */
class BoardDuplicationService
{
    /**
     * Source column id => copy column id, collected across every tab copied
     * by one `duplicateAllViews()` call.
     *
     * @var array<int, int>
     */
    private array $column_id_map = [];

    /**
     * @param  array<string, mixed>  $overrides  Attributes to override on the copied view (e.g. `label`, `position`, `is_primary`).
     * @param  array<string, mixed>  $state_overrides  Saved filter/sort/display state to keep on the copy instead of the source's
     *                                                 (the toolbar's "Save as new view"). Still expressed in the source's column and
     *                                                 group ids, which are remapped onto the copy like the source's own state.
     */
    public function duplicateView(
        BoardView $source,
        WorkspaceNavigationItem $target_item,
        array $overrides = [],
        ?int $created_by_id = null,
        array $state_overrides = [],
        bool $with_items = true,
        bool $with_updates = false,
    ): BoardView {
        $source->loadMissing(['columns', 'groups.items.values']);

        return DB::transaction(function () use ($source, $target_item, $overrides, $created_by_id, $state_overrides, $with_items, $with_updates) {
            $column_id_map = [];
            $group_id_map = [];
            $column_copies = [];

            $view_copy = $target_item->views()->create(array_merge([
                'label' => $source->label,
                'view_type' => $source->view_type,
                'emoji' => $source->emoji,
                'description' => $source->description,
                'position' => $this->nextViewPosition($target_item),
                'is_primary' => false,
                'pinned' => false,
                'is_locked' => false,
                'locked_by_id' => null,
                'row_height' => $source->row_height,
                'doc_content' => $source->doc_content,
                // The builder settings are copied, the public token is not:
                // the copy gets its own link the first time it is shared.
                'form_config' => $source->form_config,
                'created_by_id' => $created_by_id,
            ], $overrides));

            foreach ($source->columns as $column) {
                $column_copy = $view_copy->columns()->create([
                    'board_id' => $target_item->id,
                    'key' => $column->key,
                    'label' => $column->label,
                    'type' => $column->type,
                    // Without the scope a subitem column would come back as an item column.
                    'scope' => $column->scope,
                    'position' => $column->position,
                    'width' => $column->width,
                    'config' => $column->config,
                    // Column permissions travel with the copy, otherwise
                    // duplicating a tab would expose restricted values.
                    'edit_restriction' => $column->edit_restriction,
                    'view_restriction' => $column->view_restriction,
                    'hideable' => $column->hideable,
                    'pinnable' => $column->pinnable,
                ]);
                $column_id_map[$column->id] = $column_copy->id;
                $column_copies[] = $column_copy;
            }

            $this->remapFormulaColumns($column_copies, $column_id_map);

            $item_id_map = [];
            foreach ($source->groups as $group) {
                $group_copy = $view_copy->groups()->create([
                    'board_id' => $target_item->id,
                    'name' => $group->name,
                    'accent_color' => $group->accent_color,
                    'is_priority' => $group->is_priority,
                    'is_archived' => $group->is_archived,
                    'archived_at' => $group->archived_at,
                    'position' => $group->position,
                ]);
                $group_id_map[$group->id] = $group_copy->id;

                if ($with_items) {
                    $item_id_map += $this->copyGroupItems($group->items, $target_item, $group_copy->id, $column_id_map);
                }
            }

            if ($with_items) {
                $this->remapDependencyValues($view_copy, $item_id_map);
            }
            if ($with_items && $with_updates) {
                $this->copyUpdates($item_id_map);
            }

            // Chart, Workload and Dashboard tabs read another tab's columns, so
            // their settings are copied as is here and remapped by
            // `duplicateAllViews()` once every tab of the board has its copy.
            $view_copy->forceFill([
                'chart_config' => $source->chart_config,
                'workload_config' => $source->workload_config,
                'dashboard_config' => $source->dashboard_config,
            ]);
            $this->column_id_map += $column_id_map;

            // An unsaved copy of the source holding the requested state, so the
            // source row itself is never modified.
            $state_source = $source->replicate();
            $state_source->forceFill($state_overrides);
            if (array_key_exists('row_height', $state_overrides)) {
                $view_copy->row_height = $state_overrides['row_height'];
            }

            $view_copy->fill($this->remapColumnReferences($state_source, $column_id_map, $group_id_map))->save();

            return $view_copy;
        });
    }

    /**
     * Duplicates every view (tab) of `$source_board` into `$target_board`,
     * preserving each tab's label, position and primary/pinned status — used
     * for a whole-board duplicate, as opposed to {@see duplicateView()}'s
     * single-tab "Duplicate this view".
     */
    public function duplicateAllViews(
        WorkspaceNavigationItem $source_board,
        WorkspaceNavigationItem $target_board,
        ?int $created_by_id = null,
        bool $with_items = true,
        bool $with_updates = false,
    ): void {
        $this->column_id_map = [];
        $view_id_map = [];

        foreach ($source_board->views()->orderBy('position')->get() as $view) {
            $copy = $this->duplicateView($view, $target_board, [
                'position' => $view->position,
                'is_primary' => $view->is_primary,
                'pinned' => $view->pinned,
            ], $created_by_id, [], $with_items, $with_updates);
            $view_id_map[$view->id] = $copy->id;
        }

        // Every tab now has its copy, so settings that point at another tab
        // (and at that tab's columns) can be moved over to the copies.
        foreach ($target_board->views()->get() as $view_copy) {
            $view_copy->forceFill([
                'chart_config' => BoardViewConfigRemapper::chart($view_copy->chart_config, $view_id_map, $this->column_id_map),
                'workload_config' => BoardViewConfigRemapper::workload($view_copy->workload_config, $view_id_map, $this->column_id_map),
                'dashboard_config' => BoardViewConfigRemapper::dashboard(
                    $view_copy->dashboard_config,
                    $source_board->id,
                    $target_board->id,
                    $view_id_map,
                    $this->column_id_map,
                ),
            ])->save();
        }
    }

    /**
     * Remaps a tab's saved filters, sort, hidden/pinned columns, grouping and
     * coloring rules onto new column and group ids, for a tab built from a
     * template snapshot ({@see BoardTemplateService}).
     *
     * @param  array<string, mixed>  $state
     * @param  array<int, int>  $column_id_map
     * @param  array<int, int>  $group_id_map
     * @return array<string, mixed>
     */
    public function remapViewState(array $state, array $column_id_map, array $group_id_map): array
    {
        $holder = new BoardView;
        $holder->forceFill($state);

        return $this->remapColumnReferences($holder, $column_id_map, $group_id_map);
    }

    /**
     * Copies a group's items, keeping each subitem under its own copied
     * parent (a group's `items` relation lists subitems too, flattened).
     *
     * @param  Collection<int, BoardItem>  $items
     * @param  array<int, int>  $column_id_map
     * @return array<int, int> source item id => copy item id
     */
    private function copyGroupItems(Collection $items, WorkspaceNavigationItem $target_item, int $group_id, array $column_id_map): array
    {
        $item_id_map = [];
        $children_by_parent = $items->groupBy(fn (BoardItem $item) => $item->parent_id ?? 0);
        $item_ids = $items->pluck('id')->flip();

        // Roots first, then each level of children under its copied parent.
        // A subitem whose parent sits in another group is treated as a root
        // of this group rather than being dropped.
        $queue = $items->filter(fn (BoardItem $item) => $item->parent_id === null || ! $item_ids->has($item->parent_id))->values()->all();

        while ($queue !== []) {
            /** @var BoardItem $source_item */
            $source_item = array_shift($queue);

            $item_copy = $target_item->items()->create([
                'group_id' => $group_id,
                'parent_id' => $source_item->parent_id !== null ? ($item_id_map[$source_item->parent_id] ?? null) : null,
                'name' => $source_item->name,
                'description' => $source_item->description,
                'position' => $source_item->position,
                'is_priority' => $source_item->is_priority,
                'is_archived' => $source_item->is_archived,
                'created_by_id' => $source_item->created_by_id,
            ]);
            $item_id_map[$source_item->id] = $item_copy->id;

            foreach ($source_item->values as $source_value) {
                $target_column_id = $column_id_map[$source_value->column_id] ?? null;
                if ($target_column_id === null) {
                    continue;
                }

                $item_copy->values()->create([
                    'column_id' => $target_column_id,
                    'value' => $source_value->value,
                ]);
            }

            foreach ($children_by_parent->get($source_item->id, collect()) as $child) {
                $queue[] = $child;
            }
        }

        return $item_id_map;
    }

    /**
     * A Dependency value lists predecessor item ids, which now have copies of
     * their own. Ids with no copy (items of another tab) are dropped.
     *
     * @param  array<int, int>  $item_id_map
     */
    private function remapDependencyValues(BoardView $view_copy, array $item_id_map): void
    {
        $dependency_column_ids = $view_copy->columns()->where('type', BoardColumn::TYPE_DEPENDENCY)->pluck('id');
        if ($dependency_column_ids->isEmpty()) {
            return;
        }

        BoardItemValue::whereIn('column_id', $dependency_column_ids)
            ->whereIn('item_id', array_values($item_id_map))
            ->get()
            ->each(function (BoardItemValue $value) use ($item_id_map) {
                if (! is_array($value->value)) {
                    return;
                }
                $value->value = array_values(array_filter(array_map(
                    fn ($id) => isset($item_id_map[(int) $id]) ? (string) $item_id_map[(int) $id] : null,
                    $value->value
                )));
                $value->save();
            });
    }

    /**
     * Copies every posted update (and its replies) of the source items onto
     * their copies, keeping the author and the original dates. Scheduled
     * updates that were not sent yet are left behind.
     *
     * @param  array<int, int>  $item_id_map
     */
    private function copyUpdates(array $item_id_map): void
    {
        $comments = BoardItemComment::query()
            ->whereIn('item_id', array_keys($item_id_map))
            ->where(fn ($query) => $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->orderByRaw('parent_id IS NOT NULL')
            ->orderBy('id')
            ->get();

        $comment_id_map = [];
        foreach ($comments as $comment) {
            if ($comment->parent_id !== null && ! isset($comment_id_map[$comment->parent_id])) {
                continue;
            }

            $copy = new BoardItemComment([
                'item_id' => $item_id_map[$comment->item_id],
                'parent_id' => $comment->parent_id !== null ? $comment_id_map[$comment->parent_id] : null,
                'user_id' => $comment->user_id,
                'body' => $comment->body,
                'scheduled_at' => $comment->scheduled_at,
                'edited_at' => $comment->edited_at,
                'pinned' => $comment->pinned,
                'resolved_at' => $comment->resolved_at,
                'resolved_by_id' => $comment->resolved_by_id,
            ]);
            $copy->created_at = $comment->created_at;
            $copy->updated_at = $comment->updated_at;
            $copy->save();

            $comment_id_map[$comment->id] = $copy->id;
        }
    }

    /**
     * A formula column names the columns it reads by id, so its copy would
     * still read the source tab's columns. Repoints each one at the freshly
     * cloned column, both in the expression and in the legacy
     * `source_column_ids` list.
     *
     * @param  array<int, BoardColumn>  $column_copies
     * @param  array<int, int>  $column_id_map  source column id => copy column id
     */
    private function remapFormulaColumns(array $column_copies, array $column_id_map): void
    {
        foreach ($column_copies as $column_copy) {
            $config = $column_copy->config;

            // A Mirror column reads through a Connect board column of the same tab.
            if ($column_copy->type === BoardColumn::TYPE_MIRROR && is_array($config) && isset($config['source_column_id'])) {
                $mapped_id = $column_id_map[(int) $config['source_column_id']] ?? null;
                if ($mapped_id !== null) {
                    $config['source_column_id'] = is_string($config['source_column_id']) ? (string) $mapped_id : $mapped_id;
                }
                $column_copy->config = $config;
                $column_copy->save();

                continue;
            }

            if ($column_copy->type !== BoardColumn::TYPE_FORMULA || ! is_array($config)) {
                continue;
            }

            if (isset($config['expression']) && is_string($config['expression'])) {
                $config['expression'] = FormulaReferences::remap($config['expression'], $column_id_map);
            }

            if (isset($config['source_column_ids']) && is_array($config['source_column_ids'])) {
                $config['source_column_ids'] = array_map(fn ($id) => $column_id_map[$id] ?? $id, $config['source_column_ids']);
            }

            $column_copy->config = $config;
            $column_copy->save();
        }
    }

    private function nextViewPosition(WorkspaceNavigationItem $item): int
    {
        return (int) $item->views()->max('position') + 1;
    }

    /**
     * Rebuilds every saved-state field that references a column id so a
     * duplicated tab's saved filters/sort/columns/grouping point at its own
     * freshly cloned columns instead of the source tab's.
     *
     * Rules and Quick filters picks on the virtual Group field hold group ids
     * as values, which are remapped onto the copied groups the same way.
     *
     * @param  array<int, int>  $column_id_map  source column id => copy column id
     * @param  array<int, int>  $group_id_map  source group id => copy group id
     * @return array<string, mixed>
     */
    private function remapColumnReferences(BoardView $source, array $column_id_map, array $group_id_map = []): array
    {
        $filter_state = $source->filter_state;
        if ($filter_state) {
            $filter_state['search_column_ids'] = $this->remapIdList($filter_state['search_column_ids'] ?? [], $column_id_map);

            $filter_state['advanced_filter_rows'] = $this->remapFilterRules($filter_state['advanced_filter_rows'] ?? [], $column_id_map, $group_id_map);

            if (isset($filter_state['advanced_filter_groups']) && is_array($filter_state['advanced_filter_groups'])) {
                $filter_state['advanced_filter_groups'] = collect($filter_state['advanced_filter_groups'])
                    ->filter(fn ($group) => is_array($group))
                    ->map(fn (array $group) => [
                        ...$group,
                        'rules' => $this->remapFilterRules($group['rules'] ?? [], $column_id_map, $group_id_map),
                    ])
                    ->values()
                    ->all();
            }

            // Quick filters picks and exclusions share one shape: facet (column) id => option ids.
            foreach (['quick_filter_selections', 'quick_filter_exclusions'] as $key) {
                if ($key === 'quick_filter_exclusions' && ! isset($filter_state[$key])) {
                    continue;
                }
                $filter_state[$key] = collect($filter_state[$key] ?? [])
                    ->mapWithKeys(fn ($option_ids, $facet_id) => [
                        $this->remapId((string) $facet_id, $column_id_map) => (string) $facet_id === BoardItemFilterEvaluator::GROUP_FIELD_ID
                            ? $this->remapGroupIds((array) $option_ids, $group_id_map)
                            : $option_ids,
                    ])
                    ->all();
            }

            if (isset($filter_state['person_column_ids']) && is_array($filter_state['person_column_ids'])) {
                $filter_state['person_column_ids'] = $this->remapIdList($filter_state['person_column_ids'], $column_id_map);
            }

            if (isset($filter_state['quick_filter_column_ids']) && is_array($filter_state['quick_filter_column_ids'])) {
                $filter_state['quick_filter_column_ids'] = $this->remapIdList($filter_state['quick_filter_column_ids'], $column_id_map);
            }
        }

        $sort_state = $source->sort_state === null ? null : collect($source->sort_state)
            ->map(fn (array $rule) => [
                ...$rule,
                'sort_option_id' => $this->remapId($rule['sort_option_id'] ?? null, $column_id_map),
            ])
            ->all();

        $conditional_color_rules = $source->conditional_color_rules === null ? null : collect($source->conditional_color_rules)
            ->map(fn (array $rule) => [
                ...$rule,
                'column_id' => $this->remapId($rule['column_id'] ?? null, $column_id_map),
            ])
            ->all();

        return [
            'filter_state' => $filter_state,
            'sort_state' => $sort_state,
            'hidden_column_ids' => $source->hidden_column_ids === null ? null : $this->remapIdList($source->hidden_column_ids, $column_id_map),
            'pinned_column_ids' => $source->pinned_column_ids === null ? null : $this->remapIdList($source->pinned_column_ids, $column_id_map),
            'conditional_color_rules' => $conditional_color_rules,
            'group_by_option_id' => $this->remapId($source->group_by_option_id, $column_id_map),
        ];
    }

    /**
     * @param  array<int, mixed>  $rules
     * @param  array<int, int>  $column_id_map
     * @param  array<int, int>  $group_id_map
     * @return array<int, array<string, mixed>>
     */
    private function remapFilterRules(array $rules, array $column_id_map, array $group_id_map): array
    {
        return collect($rules)
            ->filter(fn ($rule) => is_array($rule))
            ->map(function (array $rule) use ($column_id_map, $group_id_map) {
                $remapped = [
                    ...$rule,
                    'column_id' => $this->remapId($rule['column_id'] ?? null, $column_id_map),
                ];
                if (($rule['column_id'] ?? null) === BoardItemFilterEvaluator::GROUP_FIELD_ID && isset($rule['values']) && is_array($rule['values'])) {
                    $remapped['values'] = $this->remapGroupIds($rule['values'], $group_id_map);
                }

                return $remapped;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $group_ids
     * @param  array<int, int>  $group_id_map
     * @return array<int, string>
     */
    private function remapGroupIds(array $group_ids, array $group_id_map): array
    {
        return array_map(
            fn ($id) => isset($group_id_map[(int) $id]) && ctype_digit((string) $id) ? (string) $group_id_map[(int) $id] : (string) $id,
            array_values($group_ids),
        );
    }

    /**
     * @param  array<int, int>  $column_id_map
     * @return array<int, string|null>
     */
    private function remapIdList(array $ids, array $column_id_map): array
    {
        return array_map(fn ($id) => $this->remapId($id, $column_id_map), $ids);
    }

    /**
     * Remaps a single column-id reference. A Group-by id may carry a bucket
     * suffix (`"12:month"`), which is kept. Non-numeric values (e.g. the
     * `"name"` sort sentinel or the `"default"` group-by sentinel) are left
     * untouched, as is any id with no corresponding entry in the map.
     *
     * @param  array<int, int>  $column_id_map
     */
    private function remapId(?string $id, array $column_id_map): ?string
    {
        if ($id === null || preg_match('/^(\d+)(:[a-z_]+)?$/', $id, $matches) !== 1) {
            return $id;
        }

        $column_id = (int) $matches[1];
        $suffix = $matches[2] ?? '';

        return isset($column_id_map[$column_id]) ? $column_id_map[$column_id].$suffix : $id;
    }
}
