<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\BoardTag;
use App\Models\BoardTemplate;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Support\BoardViewConfigRemapper;
use App\Support\BuiltInBoardTemplates;
use App\Support\FormulaReferences;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a board into a template snapshot and a snapshot back into a board.
 *
 * A snapshot is plain JSON so it can be stored ({@see BoardTemplate})
 * or written by hand ({@see BuiltInBoardTemplates}):
 *
 *     version: 1
 *     board:  { item_column_label }
 *     tags:   [{ ref, label, color }]
 *     views:  [{ ref, label, view_type, emoji, description, is_primary, pinned, row_height,
 *                doc_content, chart_config, workload_config, dashboard_config,
 *                filter_state, sort_state, group_by_option_id, hidden_column_ids,
 *                pinned_column_ids, conditional_color_rules,
 *                columns: [{ ref, key, label, type, scope, position, width, config, hideable, pinnable }],
 *                groups:  [{ ref, name, accent_color, is_priority, position,
 *                            items: [{ ref, name, description, is_priority, values: { column_ref: value }, children: [...] }] }] }]
 *
 * Every `ref` is a numeric string (the source id for a captured board), so
 * the id maps built while creating the board can remap filters, formulas and
 * Chart/Workload/Dashboard settings with the same helpers a duplicate uses.
 */
class BoardTemplateService
{
    public const SNAPSHOT_VERSION = 1;

    /** Column kinds whose values never travel into a template. */
    private const SKIPPED_VALUE_TYPES = [
        BoardColumn::TYPE_FILES,
        BoardColumn::TYPE_FORMULA,
        BoardColumn::TYPE_MIRROR,
        BoardColumn::TYPE_AUTO_NUMBER,
        BoardColumn::TYPE_VOTE,
    ];

    public function __construct(private readonly BoardDuplicationService $duplication_service) {}

    /**
     * @return array<string, mixed>
     */
    public function capture(WorkspaceNavigationItem $board, bool $with_items): array
    {
        $views = $board->views()->orderBy('position')->with(['columns', 'groups' => fn ($query) => $query->where('is_archived', false)])->get();

        $snapshot_views = [];
        foreach ($views as $view) {
            $columns = $view->columns->sortBy([['scope', 'asc'], ['position', 'asc']])->values();
            $columns_by_id = $columns->keyBy('id');

            $groups = [];
            foreach ($view->groups->sortBy('position') as $group) {
                $items = $with_items
                    ? BoardItem::where('group_id', $group->id)->where('is_archived', false)->with('values')->orderBy('position')->get()
                    : collect();

                $groups[] = [
                    'ref' => (string) $group->id,
                    'name' => $group->name,
                    'accent_color' => $group->accent_color,
                    'is_priority' => (bool) $group->is_priority,
                    'position' => $group->position,
                    'items' => $this->itemTree($items, null, $columns_by_id),
                ];
            }

            $snapshot_views[] = [
                'ref' => (string) $view->id,
                'label' => $view->label,
                'view_type' => $view->view_type,
                'emoji' => $view->emoji,
                'description' => $view->description,
                'is_primary' => (bool) $view->is_primary,
                'pinned' => (bool) $view->pinned,
                'row_height' => $view->row_height,
                'doc_content' => $view->doc_content,
                'chart_config' => $view->chart_config,
                'workload_config' => $view->workload_config,
                'dashboard_config' => $this->detachOwnBoard($view->dashboard_config, $board->id),
                'filter_state' => $view->filter_state,
                'sort_state' => $view->sort_state,
                'group_by_option_id' => $view->group_by_option_id,
                'hidden_column_ids' => $view->hidden_column_ids,
                'pinned_column_ids' => $view->pinned_column_ids,
                'conditional_color_rules' => $view->conditional_color_rules,
                'columns' => $columns->map(fn (BoardColumn $column) => [
                    'ref' => (string) $column->id,
                    'key' => $column->key,
                    'label' => $column->label,
                    'type' => $column->type,
                    'scope' => $column->scope,
                    'position' => $column->position,
                    'width' => $column->width,
                    'config' => $column->config,
                    'hideable' => $column->hideable,
                    'pinnable' => $column->pinnable,
                ])->all(),
                'groups' => $groups,
            ];
        }

        // Tags are board wide, every one of them is kept so the Tags column's
        // picker offers the same list on the new board.
        $tags = BoardTag::where('board_id', $board->id)->orderBy('position')->get()
            ->map(fn (BoardTag $tag) => ['ref' => (string) $tag->id, 'label' => $tag->label, 'color' => $tag->color])
            ->all();

        return [
            'version' => self::SNAPSHOT_VERSION,
            'board' => ['item_column_label' => $board->item_column_label],
            'tags' => $tags,
            'views' => $snapshot_views,
        ];
    }

    /**
     * Creates a new board in `$workspace` from a snapshot, returning it.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function instantiate(array $snapshot, Workspace $workspace, ?int $parent_id, string $label, User $user, string $board_type = WorkspaceNavigationItem::BOARD_TYPE_MAIN): WorkspaceNavigationItem
    {
        return DB::transaction(function () use ($snapshot, $workspace, $parent_id, $label, $user, $board_type) {
            $board = $workspace->navigationItems()->create([
                'parent_id' => $parent_id,
                'type' => WorkspaceNavigationItem::TYPE_LEAF,
                'label' => $label,
                'slug' => $this->uniqueSlug($workspace, $parent_id, $label),
                'view_key' => 'board',
                'board_type' => $board_type,
                'item_column_label' => $snapshot['board']['item_column_label'] ?? null,
                'position' => (int) $workspace->navigationItems()->where('parent_id', $parent_id)->max('position') + 1,
                'created_by_id' => $user->id,
            ]);

            $tag_map = [];
            foreach ($snapshot['tags'] ?? [] as $position => $tag) {
                $copy = BoardTag::create(['board_id' => $board->id, 'label' => $tag['label'], 'color' => $tag['color'] ?? '#579bfc', 'position' => $position]);
                $tag_map[(int) $tag['ref']] = $copy->id;
            }

            $view_map = [];
            $column_map = [];
            $group_map = [];
            $item_map = [];
            $views = [];
            $has_primary = collect($snapshot['views'] ?? [])->contains(fn ($view) => ! empty($view['is_primary']));

            foreach (array_values($snapshot['views'] ?? []) as $index => $view_data) {
                $view = $board->views()->create([
                    'label' => $view_data['label'] ?? 'Main table',
                    'view_type' => $view_data['view_type'] ?? 'table',
                    'emoji' => $view_data['emoji'] ?? null,
                    'description' => $view_data['description'] ?? null,
                    'position' => $index,
                    'is_primary' => $has_primary ? ! empty($view_data['is_primary']) : $index === 0,
                    'pinned' => ! empty($view_data['pinned']),
                    'row_height' => $view_data['row_height'] ?? 'single',
                    'doc_content' => $view_data['doc_content'] ?? null,
                    'created_by_id' => $user->id,
                ]);
                $view_map[(int) $view_data['ref']] = $view->id;
                $views[] = [$view, $view_data];

                $column_types = [];
                foreach ($view_data['columns'] ?? [] as $column_data) {
                    $column = $view->columns()->create([
                        'board_id' => $board->id,
                        'key' => $column_data['key'] ?? Str::snake($column_data['label']).'_'.Str::lower(Str::random(4)),
                        'label' => $column_data['label'],
                        'type' => $column_data['type'],
                        'scope' => $column_data['scope'] ?? BoardColumn::SCOPE_ITEM,
                        'position' => $column_data['position'] ?? 0,
                        'width' => $column_data['width'] ?? null,
                        'config' => $column_data['config'] ?? null,
                        'hideable' => $column_data['hideable'] ?? true,
                        'pinnable' => $column_data['pinnable'] ?? true,
                    ]);
                    $column_map[(int) $column_data['ref']] = $column->id;
                    $column_types[$column->id] = $column->type;
                }

                foreach ($view_data['groups'] ?? [] as $position => $group_data) {
                    $group = $view->groups()->create([
                        'board_id' => $board->id,
                        'name' => $group_data['name'],
                        'accent_color' => $group_data['accent_color'] ?? '#579bfc',
                        'is_priority' => ! empty($group_data['is_priority']),
                        'position' => $group_data['position'] ?? $position,
                    ]);
                    $group_map[(int) $group_data['ref']] = $group->id;

                    $this->createItems($board, $group, $group_data['items'] ?? [], null, $column_map, $column_types, $tag_map, $item_map, $user);
                }
            }

            $this->remapItemReferences($board, $item_map);

            foreach ($views as [$view, $view_data]) {
                $this->remapColumnConfigs($view, $column_map);

                $state = $this->duplication_service->remapViewState([
                    'filter_state' => $view_data['filter_state'] ?? null,
                    'sort_state' => $view_data['sort_state'] ?? null,
                    'group_by_option_id' => $view_data['group_by_option_id'] ?? null,
                    'hidden_column_ids' => $view_data['hidden_column_ids'] ?? null,
                    'pinned_column_ids' => $view_data['pinned_column_ids'] ?? null,
                    'conditional_color_rules' => $view_data['conditional_color_rules'] ?? null,
                ], $column_map, $group_map);

                $view->forceFill($state + [
                    'chart_config' => BoardViewConfigRemapper::chart($view_data['chart_config'] ?? null, $view_map, $column_map),
                    'workload_config' => BoardViewConfigRemapper::workload($view_data['workload_config'] ?? null, $view_map, $column_map),
                    // Widgets reading the template's own board hold `null` (see `capture()`), so no source board id is needed.
                    'dashboard_config' => BoardViewConfigRemapper::dashboard(
                        $view_data['dashboard_config'] ?? null,
                        0,
                        $board->id,
                        $view_map,
                        $column_map,
                    ),
                ])->save();
            }

            return $board;
        });
    }

    /**
     * Counts and names shown in the template gallery's preview.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function summarize(array $snapshot): array
    {
        $views = collect($snapshot['views'] ?? []);
        $primary = $views->firstWhere('is_primary', true) ?? $views->first() ?? [];
        $count_items = function (array $items) use (&$count_items): int {
            return array_reduce($items, fn (int $total, array $item) => $total + 1 + $count_items($item['children'] ?? []), 0);
        };

        return [
            'views' => $views->map(fn ($view) => ['label' => $view['label'] ?? '', 'view_type' => $view['view_type'] ?? 'table'])->values()->all(),
            'columns' => collect($primary['columns'] ?? [])
                ->where('scope', '!=', BoardColumn::SCOPE_SUBITEM)
                ->map(fn ($column) => ['label' => $column['label'], 'type' => $column['type']])
                ->values()
                ->all(),
            'groups' => collect($primary['groups'] ?? [])->map(fn ($group) => [
                'name' => $group['name'],
                'color' => $group['accent_color'] ?? null,
                'item_names' => collect($group['items'] ?? [])->take(4)->pluck('name')->all(),
                'item_count' => $count_items($group['items'] ?? []),
            ])->values()->all(),
            'item_count' => collect($primary['groups'] ?? [])->sum(fn ($group) => $count_items($group['items'] ?? [])),
        ];
    }

    /**
     * @param  Collection<int, BoardItem>  $items
     * @param  Collection<int, BoardColumn>  $columns_by_id
     * @return array<int, array<string, mixed>>
     */
    private function itemTree(Collection $items, ?int $parent_id, Collection $columns_by_id): array
    {
        return $items
            ->filter(fn (BoardItem $item) => $item->parent_id === $parent_id)
            ->map(function (BoardItem $item) use ($items, $columns_by_id) {
                $values = [];
                foreach ($item->values as $value) {
                    $column = $columns_by_id->get($value->column_id);
                    if ($column === null || in_array($column->type, self::SKIPPED_VALUE_TYPES, true) || $value->value === null) {
                        continue;
                    }
                    $raw = $value->value;
                    if ($column->type === BoardColumn::TYPE_TIME_TRACKING && is_array($raw)) {
                        $raw['running_since'] = null;
                    }
                    $values[(string) $column->id] = $raw;
                }

                return [
                    'ref' => (string) $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'is_priority' => (bool) $item->is_priority,
                    'values' => $values,
                    'children' => $this->itemTree($items, $item->id, $columns_by_id),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, int>  $column_map
     * @param  array<int, string>  $column_types
     * @param  array<int, int>  $tag_map
     * @param  array<int, int>  $item_map
     */
    private function createItems(
        WorkspaceNavigationItem $board,
        BoardGroup $group,
        array $items,
        ?int $parent_id,
        array $column_map,
        array $column_types,
        array $tag_map,
        array &$item_map,
        User $user,
    ): void {
        foreach (array_values($items) as $position => $item_data) {
            $item = $board->items()->create([
                'group_id' => $group->id,
                'parent_id' => $parent_id,
                'name' => $item_data['name'],
                'description' => $item_data['description'] ?? null,
                'is_priority' => ! empty($item_data['is_priority']),
                'position' => $position,
                'created_by_id' => $user->id,
            ]);
            if (isset($item_data['ref'])) {
                $item_map[(int) $item_data['ref']] = $item->id;
            }

            foreach ($item_data['values'] ?? [] as $column_ref => $value) {
                $column_id = $column_map[(int) $column_ref] ?? null;
                if ($column_id === null || $value === null) {
                    continue;
                }
                if ($column_types[$column_id] === BoardColumn::TYPE_TAGS && is_array($value)) {
                    $value = array_values(array_filter(array_map(
                        fn ($id) => isset($tag_map[(int) $id]) ? (is_string($id) ? (string) $tag_map[(int) $id] : $tag_map[(int) $id]) : null,
                        $value
                    )));
                }
                $item->values()->create(['column_id' => $column_id, 'value' => $value]);
            }

            $this->createItems($board, $group, $item_data['children'] ?? [], $item->id, $column_map, $column_types, $tag_map, $item_map, $user);
        }
    }

    /**
     * Dependency values name predecessor items, which now have new ids.
     *
     * @param  array<int, int>  $item_map
     */
    private function remapItemReferences(WorkspaceNavigationItem $board, array $item_map): void
    {
        $dependency_column_ids = BoardColumn::where('board_id', $board->id)->where('type', BoardColumn::TYPE_DEPENDENCY)->pluck('id');
        if ($dependency_column_ids->isEmpty()) {
            return;
        }

        BoardItemValue::whereIn('column_id', $dependency_column_ids)->get()->each(function ($value) use ($item_map) {
            if (! is_array($value->value)) {
                return;
            }
            $value->value = array_values(array_filter(array_map(fn ($id) => isset($item_map[(int) $id]) ? (string) $item_map[(int) $id] : null, $value->value)));
            $value->save();
        });
    }

    /**
     * Formula expressions and Mirror columns name other columns by id.
     *
     * @param  array<int, int>  $column_map
     */
    private function remapColumnConfigs(BoardView $view, array $column_map): void
    {
        foreach ($view->columns()->whereIn('type', [BoardColumn::TYPE_FORMULA, BoardColumn::TYPE_MIRROR])->get() as $column) {
            $config = $column->config;
            if (! is_array($config)) {
                continue;
            }
            if (isset($config['expression']) && is_string($config['expression'])) {
                $config['expression'] = FormulaReferences::remap($config['expression'], $column_map);
            }
            if (isset($config['source_column_ids']) && is_array($config['source_column_ids'])) {
                $config['source_column_ids'] = array_map(fn ($id) => $column_map[(int) $id] ?? $id, $config['source_column_ids']);
            }
            if (isset($config['source_column_id']) && isset($column_map[(int) $config['source_column_id']])) {
                $config['source_column_id'] = (string) $column_map[(int) $config['source_column_id']];
            }
            $column->config = $config;
            $column->save();
        }
    }

    /**
     * Widgets that read the captured board itself are stored as reading
     * "this board" (`null`), so they read the new board once used.
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    private function detachOwnBoard(?array $config, int $board_id): ?array
    {
        if (! is_array($config['widgets'] ?? null)) {
            return $config;
        }

        $config['widgets'] = array_map(function ($widget) use ($board_id) {
            if (is_array($widget) && isset($widget['source_board_id']) && (int) $widget['source_board_id'] === $board_id) {
                $widget['source_board_id'] = null;
            }

            return $widget;
        }, $config['widgets']);

        return $config;
    }

    private function uniqueSlug(Workspace $workspace, ?int $parent_id, string $label): string
    {
        $base = Str::slug($label) ?: 'item';
        $slug = $base;
        $suffix = 1;

        while ($workspace->navigationItems()->where('parent_id', $parent_id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
