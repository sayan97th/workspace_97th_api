<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\BoardView;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Aggregates a board's data into chart-ready categories/series for a
 * `chart`-type {@see BoardView}. A fresh chart tab has no items of its own
 * (like every new tab), so it visualizes another tab on the same board — the
 * `source_view_id` in its `chart_config` — the way a real Monday chart
 * widget visualizes a board. Every config key falls back to a sane default
 * when unset or stale (a since-deleted column/tab), so this never errors on
 * a half-configured or since-edited chart; it just resolves the same
 * defaults a brand-new chart tab would use.
 */
class ChartDataService
{
    /** Sentinel `group_by`/`split_by` value meaning "bucket by the board's own tables (groups)", not a column. */
    private const GROUP_SENTINEL = '__group__';

    /** Sentinel bucket key for an item with no value in the chosen dimension. */
    private const NONE_KEY = '__none__';

    private const CHARTABLE_GROUP_TYPES = [
        BoardColumn::TYPE_STATUS,
        BoardColumn::TYPE_TAGS,
        BoardColumn::TYPE_DROPDOWN,
        BoardColumn::TYPE_PEOPLE,
        BoardColumn::TYPE_DATE,
        BoardColumn::TYPE_CHECKBOX,
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(WorkspaceNavigationItem $board, BoardView $chart_view): array
    {
        $config = $chart_view->chart_config ?? [];
        $source_view = $this->resolveSourceView($board, $config['source_view_id'] ?? null);

        if ($source_view === null) {
            return $this->emptyResult($board, $config);
        }

        $source_view->loadMissing(['columns', 'groups.items.values']);
        $columns_by_id = $source_view->columns->keyBy('id');

        $group_by_columns = $this->groupByColumnOptions($source_view);
        $value_columns = $this->valueColumnOptions($source_view);

        $group_by_column_id = $this->resolveGroupByColumnId($config['group_by_column_id'] ?? null, $group_by_columns);
        $split_by_column_id = $this->resolveSplitByColumnId($config['split_by_column_id'] ?? null, $columns_by_id, $group_by_column_id);
        $aggregate_fn = $this->resolveAggregateFn($config['aggregate_fn'] ?? null);
        $value_column_id = $this->resolveValueColumnId($config['value_column_id'] ?? null, $columns_by_id, $aggregate_fn);
        if ($value_column_id === null && $aggregate_fn !== 'count') {
            // No number column to sum/average on this board — fall back to counting items instead of charting all-zero bars.
            $aggregate_fn = 'count';
        }
        $date_bucket = $this->resolveDateBucket($config['date_bucket'] ?? null);
        $chart_type = $this->resolveChartType($config['chart_type'] ?? null);

        $resolved_config = [
            'chart_type' => $chart_type,
            'source_view_id' => $source_view->id,
            'group_by_column_id' => $group_by_column_id,
            'split_by_column_id' => $split_by_column_id,
            'aggregate_fn' => $aggregate_fn,
            'value_column_id' => $value_column_id,
            'date_bucket' => $date_bucket,
        ];

        $items = $source_view->groups->flatMap(fn (BoardGroup $group) => $group->items);

        if ($items->isEmpty()) {
            return $this->emptyResult($board, $config, $group_by_columns, $value_columns, $resolved_config);
        }

        [$categories, $series, $total] = $this->aggregate(
            $items,
            $columns_by_id,
            $source_view->groups,
            $group_by_column_id,
            $split_by_column_id,
            $aggregate_fn,
            $value_column_id,
            $date_bucket,
        );

        return [
            'config' => $resolved_config,
            'categories' => $categories,
            'series' => $series,
            'total' => $total,
            'has_data' => true,
            'source_views' => $this->sourceViewOptions($board),
            'group_by_columns' => $group_by_columns,
            'value_columns' => $value_columns,
        ];
    }

    /**
     * The "nothing to chart yet" payload — either no source tab exists at all
     * (`$resolved_config`/`$group_by_columns`/`$value_columns` omitted), or a
     * source tab was resolved but has zero items (all three supplied by
     * `build()` so the config panel can still render with real options).
     *
     * @param  array<string, mixed>  $config  the view's own (unresolved) chart_config, for its `chart_type`/`date_bucket` picks to survive even with nothing to chart
     * @param  array<int, array<string, string>>  $group_by_columns
     * @param  array<int, array<string, string>>  $value_columns
     * @param  array<string, mixed>|null  $resolved_config
     * @return array<string, mixed>
     */
    private function emptyResult(WorkspaceNavigationItem $board, array $config, array $group_by_columns = [], array $value_columns = [], ?array $resolved_config = null): array
    {
        return [
            'config' => $resolved_config ?? [
                'chart_type' => $this->resolveChartType($config['chart_type'] ?? null),
                'source_view_id' => $config['source_view_id'] ?? null,
                'group_by_column_id' => self::GROUP_SENTINEL,
                'split_by_column_id' => null,
                'aggregate_fn' => 'count',
                'value_column_id' => null,
                'date_bucket' => $this->resolveDateBucket($config['date_bucket'] ?? null),
            ],
            'categories' => [],
            'series' => [],
            'total' => 0,
            'has_data' => false,
            'source_views' => $this->sourceViewOptions($board),
            'group_by_columns' => $group_by_columns,
            'value_columns' => $value_columns,
        ];
    }

    /**
     * The board's own tabs a chart can pull data from — every tab except
     * other chart tabs (which have no items of their own to chart).
     *
     * @return array<int, array<string, mixed>>
     */
    private function sourceViewOptions(WorkspaceNavigationItem $board): array
    {
        return $board->views()
            ->where('view_type', '!=', 'chart')
            ->orderBy('position')
            ->get(['id', 'label', 'is_primary'])
            ->map(fn (BoardView $view) => ['id' => $view->id, 'label' => $view->label, 'is_primary' => $view->is_primary])
            ->values()
            ->all();
    }

    private function resolveSourceView(WorkspaceNavigationItem $board, ?int $configured_id): ?BoardView
    {
        $configured = $configured_id !== null
            ? $board->views()->where('id', $configured_id)->where('view_type', '!=', 'chart')->first()
            : null;

        return $configured
            ?? $board->views()->where('is_primary', true)->first()
            ?? $board->views()->where('view_type', '!=', 'chart')->orderBy('position')->first();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function groupByColumnOptions(BoardView $source_view): array
    {
        $options = [
            ['id' => self::GROUP_SENTINEL, 'label' => 'Table (group)', 'type' => 'group'],
        ];

        foreach ($source_view->columns as $column) {
            if (in_array($column->type, self::CHARTABLE_GROUP_TYPES, true)) {
                $options[] = ['id' => (string) $column->id, 'label' => $column->label, 'type' => $column->type];
            }
        }

        return $options;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function valueColumnOptions(BoardView $source_view): array
    {
        return $source_view->columns
            ->filter(fn (BoardColumn $column) => $column->type === BoardColumn::TYPE_NUMBER)
            ->map(fn (BoardColumn $column) => ['id' => (string) $column->id, 'label' => $column->label, 'type' => $column->type])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, string>>  $group_by_columns
     */
    private function resolveGroupByColumnId(?string $configured, array $group_by_columns): string
    {
        $valid_ids = array_column($group_by_columns, 'id');
        if ($configured !== null && in_array($configured, $valid_ids, true)) {
            return $configured;
        }

        // Index 0 is always the group sentinel — index 1, if present, is the board's first real chartable column.
        return $valid_ids[1] ?? self::GROUP_SENTINEL;
    }

    private function resolveSplitByColumnId(?string $configured, Collection $columns_by_id, string $group_by_column_id): ?string
    {
        if ($configured === null || $configured === $group_by_column_id) {
            return null;
        }

        if ($configured === self::GROUP_SENTINEL) {
            return self::GROUP_SENTINEL;
        }

        $column = $columns_by_id->get((int) $configured);

        return $column !== null && in_array($column->type, self::CHARTABLE_GROUP_TYPES, true) ? $configured : null;
    }

    private function resolveAggregateFn(?string $configured): string
    {
        return in_array($configured, ['count', 'sum', 'average'], true) ? $configured : 'count';
    }

    private function resolveValueColumnId(?string $configured, Collection $columns_by_id, string $aggregate_fn): ?string
    {
        if ($aggregate_fn === 'count') {
            return null;
        }

        $column = $configured !== null ? $columns_by_id->get((int) $configured) : null;
        if ($column !== null && $column->type === BoardColumn::TYPE_NUMBER) {
            return $configured;
        }

        $fallback = $columns_by_id->first(fn (BoardColumn $column) => $column->type === BoardColumn::TYPE_NUMBER);

        return $fallback ? (string) $fallback->id : null;
    }

    private function resolveDateBucket(?string $configured): string
    {
        return in_array($configured, ['day', 'week', 'month'], true) ? $configured : 'day';
    }

    private function resolveChartType(?string $configured): string
    {
        return in_array($configured, ['bar', 'stacked_bar', 'line', 'pie', 'donut'], true) ? $configured : 'bar';
    }

    /**
     * @param  Collection<int, BoardItem>  $items
     * @param  Collection<int, BoardColumn>  $columns_by_id
     * @param  Collection<int, BoardGroup>  $groups
     * @return array{0: array<int, array<string, string>>, 1: array<int, array<string, mixed>>, 2: float}
     */
    private function aggregate(
        Collection $items,
        Collection $columns_by_id,
        Collection $groups,
        string $group_by,
        ?string $split_by,
        string $aggregate_fn,
        ?string $value_column_id,
        string $date_bucket,
    ): array {
        $groups_by_id = $groups->keyBy('id');
        $people_names = $this->peopleNames($items, $columns_by_id);

        /** @var array<string, array<string, array{sum: float, value_count: int, item_count: int}>> $matrix */
        $matrix = [];
        $group_order = [];
        $split_order = [];

        foreach ($items as $item) {
            $group_keys = $this->bucketKeysForDimension($item, $group_by, $columns_by_id, $date_bucket);
            // A multi-value split dimension (tags/people) only takes the item's first value, to avoid a
            // combinatorial group×split explosion — the group dimension still fans out fully.
            $split_key = $split_by !== null
                ? ($this->bucketKeysForDimension($item, $split_by, $columns_by_id, $date_bucket)[0] ?? self::NONE_KEY)
                : '__all__';

            $numeric_value = null;
            if ($aggregate_fn !== 'count' && $value_column_id !== null) {
                $raw = $item->values->firstWhere('column_id', (int) $value_column_id)?->value;
                $numeric_value = is_numeric($raw) ? (float) $raw : null;
            }

            foreach ($group_keys as $group_key) {
                if (! isset($matrix[$group_key])) {
                    $matrix[$group_key] = [];
                    $group_order[] = $group_key;
                }
                if (! isset($matrix[$group_key][$split_key])) {
                    $matrix[$group_key][$split_key] = ['sum' => 0.0, 'value_count' => 0, 'item_count' => 0];
                    if (! in_array($split_key, $split_order, true)) {
                        $split_order[] = $split_key;
                    }
                }

                $matrix[$group_key][$split_key]['item_count']++;
                if ($numeric_value !== null) {
                    $matrix[$group_key][$split_key]['sum'] += $numeric_value;
                    $matrix[$group_key][$split_key]['value_count']++;
                }
            }
        }

        $group_order = $this->orderKeys($group_order, $group_by, $columns_by_id, $groups);
        $split_order = $split_by !== null ? $this->orderKeys($split_order, $split_by, $columns_by_id, $groups) : $split_order;

        $categories = array_map(
            fn (string $key) => array_merge(
                ['key' => $key],
                $this->bucketLabelColor($key, $group_by, $columns_by_id, $groups_by_id, $people_names, $date_bucket)
            ),
            $group_order,
        );

        $series = [];
        $running_total = 0.0;
        $running_value_count = 0;

        foreach ($split_order as $split_key) {
            $data = [];
            foreach ($group_order as $group_key) {
                $cell = $matrix[$group_key][$split_key] ?? ['sum' => 0.0, 'value_count' => 0, 'item_count' => 0];
                $value = match ($aggregate_fn) {
                    'sum' => round($cell['sum'], 2),
                    'average' => $cell['value_count'] > 0 ? round($cell['sum'] / $cell['value_count'], 2) : 0,
                    default => $cell['item_count'],
                };
                $data[] = $value;
                $running_total += $aggregate_fn === 'sum' || $aggregate_fn === 'average' ? $cell['sum'] : $cell['item_count'];
                $running_value_count += $cell['value_count'];
            }

            $split_label_color = $split_by !== null
                ? $this->bucketLabelColor($split_key, $split_by, $columns_by_id, $groups_by_id, $people_names, $date_bucket)
                : ['label' => $this->aggregateSeriesLabel($aggregate_fn, $value_column_id, $columns_by_id), 'color' => null];

            $series[] = [
                'key' => $split_key,
                'name' => $split_label_color['label'],
                'color' => $split_label_color['color'],
                'data' => $data,
            ];
        }

        $total = $aggregate_fn === 'average' && $running_value_count > 0
            ? round($running_total / $running_value_count, 2)
            : round($running_total, 2);

        return [$categories, $series, $total];
    }

    /**
     * @return array<int, string>
     */
    private function bucketKeysForDimension(BoardItem $item, string $dimension, Collection $columns_by_id, string $date_bucket): array
    {
        if ($dimension === self::GROUP_SENTINEL) {
            return [(string) $item->group_id];
        }

        $column = $columns_by_id->get((int) $dimension);
        if ($column === null) {
            return [self::NONE_KEY];
        }

        $raw_value = $item->values->firstWhere('column_id', $column->id)?->value;

        return match ($column->type) {
            BoardColumn::TYPE_STATUS => $raw_value !== null && $raw_value !== '' ? [(string) $raw_value] : [self::NONE_KEY],
            BoardColumn::TYPE_TAGS, BoardColumn::TYPE_DROPDOWN => is_array($raw_value) && count($raw_value) > 0 ? array_map('strval', $raw_value) : [self::NONE_KEY],
            BoardColumn::TYPE_PEOPLE => is_array($raw_value) && count($raw_value) > 0 ? array_map('strval', $raw_value) : [self::NONE_KEY],
            BoardColumn::TYPE_CHECKBOX => [$raw_value === true ? 'true' : 'false'],
            BoardColumn::TYPE_DATE => $raw_value ? [$this->dateBucketKey((string) $raw_value, $date_bucket)] : [self::NONE_KEY],
            default => [self::NONE_KEY],
        };
    }

    /**
     * @param  Collection<int, BoardItem>  $items
     */
    private function peopleNames(Collection $items, Collection $columns_by_id): Collection
    {
        $people_column_ids = $columns_by_id->filter(fn (BoardColumn $column) => $column->type === BoardColumn::TYPE_PEOPLE)->pluck('id');
        if ($people_column_ids->isEmpty()) {
            return collect();
        }

        $user_ids = $items
            ->flatMap(fn (BoardItem $item) => $item->values)
            ->filter(fn (BoardItemValue $value) => $people_column_ids->contains($value->column_id) && is_array($value->value))
            ->flatMap(fn (BoardItemValue $value) => $value->value)
            ->map(fn ($id) => (int) $id)
            ->unique();

        if ($user_ids->isEmpty()) {
            return collect();
        }

        // `full_name` is an accessor (first_name + last_name), not a real column — pluck() can't select it.
        return User::whereIn('id', $user_ids)->get(['id', 'first_name', 'last_name'])->mapWithKeys(
            fn (User $user) => [$user->id => $user->full_name]
        );
    }

    /**
     * @return array{label: string, color: string|null}
     */
    private function bucketLabelColor(
        string $key,
        string $dimension,
        Collection $columns_by_id,
        Collection $groups_by_id,
        Collection $people_names,
        string $date_bucket,
    ): array {
        if ($key === self::NONE_KEY) {
            return ['label' => 'No value', 'color' => '#c4c4c4'];
        }

        if ($dimension === self::GROUP_SENTINEL) {
            $group = $groups_by_id->get((int) $key);

            return ['label' => $group?->name ?? 'Deleted table', 'color' => $group?->accent_color ?? '#c4c4c4'];
        }

        $column = $columns_by_id->get((int) $dimension);
        if ($column === null) {
            return ['label' => $key, 'color' => '#c4c4c4'];
        }

        return match ($column->type) {
            BoardColumn::TYPE_STATUS, BoardColumn::TYPE_TAGS, BoardColumn::TYPE_DROPDOWN => $this->optionLabelColor($column, $key),
            // People/dates carry no inherent color of their own — left null so the frontend
            // assigns one from its validated categorical palette instead of every person/date
            // rendering as the same identical hue.
            BoardColumn::TYPE_PEOPLE => ['label' => $people_names->get((int) $key, 'Unknown member'), 'color' => null],
            BoardColumn::TYPE_CHECKBOX => ['label' => $key === 'true' ? 'Done' : 'Not done', 'color' => $key === 'true' ? '#00c875' : '#c4c4c4'],
            BoardColumn::TYPE_DATE => ['label' => $this->dateBucketLabel($key, $date_bucket), 'color' => null],
            default => ['label' => $key, 'color' => null],
        };
    }

    /**
     * @return array{label: string, color: string}
     */
    private function optionLabelColor(BoardColumn $column, string $option_id): array
    {
        $option = collect($column->config['options'] ?? [])->firstWhere('id', $option_id);

        return [
            'label' => $option['label'] ?? 'Unlabeled',
            'color' => $option['color'] ?? '#c4c4c4',
        ];
    }

    private function aggregateSeriesLabel(string $aggregate_fn, ?string $value_column_id, Collection $columns_by_id): string
    {
        $value_column_label = $value_column_id !== null ? $columns_by_id->get((int) $value_column_id)?->label : null;

        return match ($aggregate_fn) {
            'sum' => 'Sum of '.($value_column_label ?? 'value'),
            'average' => 'Average of '.($value_column_label ?? 'value'),
            default => 'Count of items',
        };
    }

    private function dateBucketKey(string $iso_date, string $bucket): string
    {
        $date = Carbon::parse($iso_date);

        return match ($bucket) {
            'week' => $date->startOfWeek()->format('Y-m-d'),
            'month' => $date->format('Y-m'),
            default => $date->format('Y-m-d'),
        };
    }

    private function dateBucketLabel(string $key, string $bucket): string
    {
        return match ($bucket) {
            'week' => 'Week of '.Carbon::parse($key)->format('M j, Y'),
            'month' => Carbon::createFromFormat('Y-m', $key)->format('F Y'),
            default => Carbon::parse($key)->format('M j, Y'),
        };
    }

    /**
     * @param  array<int, string>  $keys
     * @param  Collection<int, BoardColumn>  $columns_by_id
     * @param  Collection<int, BoardGroup>  $groups
     * @return array<int, string>
     */
    private function orderKeys(array $keys, string $dimension, Collection $columns_by_id, Collection $groups): array
    {
        if ($dimension === self::GROUP_SENTINEL) {
            $position_by_id = $groups->pluck('position', 'id');
            usort($keys, fn ($a, $b) => ($position_by_id[(int) $a] ?? 0) <=> ($position_by_id[(int) $b] ?? 0));

            return $keys;
        }

        $column = $columns_by_id->get((int) $dimension);
        if ($column === null) {
            return $keys;
        }

        if (in_array($column->type, [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_TAGS, BoardColumn::TYPE_DROPDOWN], true)) {
            $option_order = collect($column->config['options'] ?? [])->pluck('id')->flip();
            usort($keys, function ($a, $b) use ($option_order) {
                if ($a === self::NONE_KEY) {
                    return 1;
                }
                if ($b === self::NONE_KEY) {
                    return -1;
                }

                return ($option_order[$a] ?? PHP_INT_MAX) <=> ($option_order[$b] ?? PHP_INT_MAX);
            });

            return $keys;
        }

        if ($column->type === BoardColumn::TYPE_DATE) {
            sort($keys);

            return $keys;
        }

        if ($column->type === BoardColumn::TYPE_CHECKBOX) {
            usort($keys, fn ($a, $b) => ($a === 'false' ? 0 : 1) <=> ($b === 'false' ? 0 : 1));

            return $keys;
        }

        return $keys;
    }
}
