<?php

namespace App\Services\Board;

use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Facades\DB;

/**
 * Deep-copies a board view (tab) — its columns, groups, items and cell
 * values, plus its saved filter/sort/display state remapped onto the freshly
 * cloned columns — into a target board. The target is usually the same
 * board the source view belongs to ({@see \App\Http\Controllers\Board\BoardViewController::duplicate()},
 * "Duplicate this view"), but is a brand-new board when the board options
 * menu's "Duplicate board" duplicates every one of a board's views in turn
 * (see {@see \App\Http\Controllers\Workspace\WorkspaceNavigationItemController::duplicate()}).
 */
class BoardDuplicationService
{
    /**
     * @param  array<string, mixed>  $overrides  Attributes to override on the copied view (e.g. `label`, `position`, `is_primary`).
     */
    public function duplicateView(BoardView $source, WorkspaceNavigationItem $target_item, array $overrides = [], ?int $created_by_id = null): BoardView
    {
        $source->loadMissing(['columns', 'groups.items.values']);

        return DB::transaction(function () use ($source, $target_item, $overrides, $created_by_id) {
            $column_id_map = [];

            $view_copy = $target_item->views()->create(array_merge([
                'label' => $source->label,
                'view_type' => $source->view_type,
                'emoji' => $source->emoji,
                'position' => $this->nextViewPosition($target_item),
                'is_primary' => false,
                'pinned' => false,
                'is_locked' => false,
                'locked_by_id' => null,
                'row_height' => $source->row_height,
                'doc_content' => $source->doc_content,
                'created_by_id' => $created_by_id,
            ], $overrides));

            foreach ($source->columns as $column) {
                $column_copy = $view_copy->columns()->create([
                    'board_id' => $target_item->id,
                    'key' => $column->key,
                    'label' => $column->label,
                    'type' => $column->type,
                    'position' => $column->position,
                    'width' => $column->width,
                    'config' => $column->config,
                    'hideable' => $column->hideable,
                    'pinnable' => $column->pinnable,
                ]);
                $column_id_map[$column->id] = $column_copy->id;
            }

            foreach ($source->groups as $group) {
                $group_copy = $view_copy->groups()->create([
                    'board_id' => $target_item->id,
                    'name' => $group->name,
                    'accent_color' => $group->accent_color,
                    'position' => $group->position,
                ]);

                foreach ($group->items as $source_item) {
                    $item_copy = $target_item->items()->create([
                        'group_id' => $group_copy->id,
                        'name' => $source_item->name,
                        'position' => $source_item->position,
                        'created_by_id' => $source_item->created_by_id,
                    ]);

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
                }
            }

            $view_copy->fill($this->remapColumnReferences($source, $column_id_map))->save();

            return $view_copy;
        });
    }

    /**
     * Duplicates every view (tab) of `$source_board` into `$target_board`,
     * preserving each tab's label, position and primary/pinned status — used
     * for a whole-board duplicate, as opposed to {@see duplicateView()}'s
     * single-tab "Duplicate this view".
     */
    public function duplicateAllViews(WorkspaceNavigationItem $source_board, WorkspaceNavigationItem $target_board, ?int $created_by_id = null): void
    {
        foreach ($source_board->views()->orderBy('position')->get() as $view) {
            $this->duplicateView($view, $target_board, [
                'position' => $view->position,
                'is_primary' => $view->is_primary,
                'pinned' => $view->pinned,
            ], $created_by_id);
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
     * @param  array<int, int>  $column_id_map  source column id => copy column id
     * @return array<string, mixed>
     */
    private function remapColumnReferences(BoardView $source, array $column_id_map): array
    {
        $filter_state = $source->filter_state;
        if ($filter_state) {
            $filter_state['search_column_ids'] = $this->remapIdList($filter_state['search_column_ids'] ?? [], $column_id_map);

            $filter_state['advanced_filter_rows'] = collect($filter_state['advanced_filter_rows'] ?? [])
                ->map(fn (array $row) => [
                    ...$row,
                    'column_id' => $this->remapId($row['column_id'] ?? null, $column_id_map),
                ])
                ->all();

            $filter_state['quick_filter_selections'] = collect($filter_state['quick_filter_selections'] ?? [])
                ->mapWithKeys(fn ($option_ids, $facet_id) => [$this->remapId((string) $facet_id, $column_id_map) => $option_ids])
                ->all();
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
     * @param  array<int, int>  $column_id_map
     * @return array<int, string|null>
     */
    private function remapIdList(array $ids, array $column_id_map): array
    {
        return array_map(fn ($id) => $this->remapId($id, $column_id_map), $ids);
    }

    /**
     * Remaps a single column-id reference. Non-numeric values (e.g. the
     * `"name"` sort sentinel or the `"default"` group-by sentinel) are left
     * untouched, as is any id with no corresponding entry in the map.
     *
     * @param  array<int, int>  $column_id_map
     */
    private function remapId(?string $id, array $column_id_map): ?string
    {
        if ($id === null || ! ctype_digit($id)) {
            return $id;
        }

        return isset($column_id_map[(int) $id]) ? (string) $column_id_map[(int) $id] : $id;
    }
}
