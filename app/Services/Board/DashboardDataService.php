<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardTag;
use App\Models\BoardView;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Support\BoardVisibility;
use Illuminate\Support\Collection;

/**
 * Computes every widget of a `dashboard`-type tab. Like monday.com's
 * dashboards, each widget reads one board tab: by default a tab of the board
 * the dashboard lives on, or a tab of any other board the viewer is allowed
 * to open (see {@see BoardVisibility}). A widget that cannot be computed (its
 * board was deleted or made private) reports an error of its own instead of
 * failing the whole dashboard.
 */
class DashboardDataService
{
    public const WIDGET_TYPES = ['numbers', 'battery', 'chart', 'status_overview', 'table', 'workload'];

    public const MAX_WIDGETS = 30;

    public const NUMBERS_FUNCTIONS = ['count', 'sum', 'average', 'min', 'max'];

    /** Column kinds a Battery or Status overview widget can summarize. */
    private const OPTION_COLUMN_TYPES = [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DROPDOWN];

    private const TABLE_MAX_COLUMNS = 5;

    private const TABLE_DEFAULT_LIMIT = 10;

    private const TABLE_MAX_LIMIT = 50;

    private const WORKLOAD_MAX_PEOPLE = 12;

    public function __construct(
        private readonly BoardViewDataSource $source,
        private readonly ChartDataService $chart_service,
    ) {}

    /**
     * The saved widgets (`config`) plus what each one computes to (`widgets`).
     *
     * @return array{config: array<string, mixed>, widgets: array<int, array<string, mixed>>}
     */
    public function build(WorkspaceNavigationItem $board, BoardView $dashboard_view, User $user): array
    {
        $widgets = $dashboard_view->dashboard_config['widgets'] ?? [];
        $results = [];

        foreach (array_slice(is_array($widgets) ? $widgets : [], 0, self::MAX_WIDGETS) as $widget) {
            if (! is_array($widget) || ! isset($widget['id'], $widget['type'])) {
                continue;
            }
            $results[] = $this->widget($board, $widget, $user);
        }

        return [
            'config' => ['widgets' => array_values(array_filter(is_array($widgets) ? $widgets : [], 'is_array'))],
            'widgets' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function widget(WorkspaceNavigationItem $board, array $widget, User $user): array
    {
        $result = ['id' => (string) $widget['id'], 'type' => (string) $widget['type'], 'data' => null, 'error' => null];

        $source_board = $this->resolveBoard($board, $widget['source_board_id'] ?? null, $user);
        if ($source_board === null) {
            return array_merge($result, [
                'source_board' => null,
                'source_view_id' => null,
                'source_views' => [],
                'columns' => [],
                'error' => 'This board is not available to you anymore.',
            ]);
        }

        $source_view = $this->source->resolveSourceView($source_board, isset($widget['source_view_id']) ? (int) $widget['source_view_id'] : null);
        $columns = $source_view ? $this->source->columns($source_view) : collect();

        $result['source_board'] = ['id' => $source_board->id, 'label' => $source_board->label];
        $result['source_view_id'] = $source_view?->id;
        $result['source_views'] = $this->source->sourceViewOptions($source_board);
        $result['columns'] = $this->source->columnOptions($columns);

        if ($source_view === null) {
            $result['error'] = 'This board has no table to read from.';

            return $result;
        }

        $settings = is_array($widget['config'] ?? null) ? $widget['config'] : [];
        $columns_by_id = $columns->keyBy('id');

        $result['data'] = match ($widget['type']) {
            'numbers' => $this->numbers($source_view, $columns_by_id, $settings),
            'battery' => $this->battery($source_view, $columns, $settings),
            'status_overview' => $this->statusOverview($source_view, $columns, $settings),
            'chart' => $this->chart($source_board, $source_view, $settings),
            'table' => $this->table($source_view, $columns, $settings),
            'workload' => $this->workload($source_view, $columns, $settings),
            default => null,
        };

        return $result;
    }

    private function resolveBoard(WorkspaceNavigationItem $board, mixed $source_board_id, User $user): ?WorkspaceNavigationItem
    {
        if ($source_board_id === null || $source_board_id === '' || (int) $source_board_id === $board->id) {
            return $board;
        }

        return BoardVisibility::query($user)->whereKey((int) $source_board_id)->first();
    }

    /**
     * A single big number: how many items, or the sum, average, lowest or
     * highest value of a Number column.
     *
     * @param  Collection<int, BoardColumn>  $columns_by_id
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function numbers(BoardView $view, Collection $columns_by_id, array $settings): array
    {
        $items = $this->source->rootItems($view);
        $function = in_array($settings['function'] ?? null, self::NUMBERS_FUNCTIONS, true) ? $settings['function'] : 'count';
        $column = isset($settings['column_id']) ? $columns_by_id->get((int) $settings['column_id']) : null;
        if ($column?->type !== BoardColumn::TYPE_NUMBER) {
            $column = null;
        }

        if ($function === 'count' || $column === null) {
            return ['function' => 'count', 'column_id' => null, 'value' => $items->count(), 'item_count' => $items->count()];
        }

        $numbers = $items
            ->map(fn (BoardItem $item) => $this->source->numberValue($this->source->rawValue($item, $column)))
            ->filter(fn (?float $value) => $value !== null)
            ->values();

        $value = match ($function) {
            'sum' => $numbers->sum(),
            'average' => $numbers->isEmpty() ? 0 : $numbers->avg(),
            'min' => $numbers->min() ?? 0,
            'max' => $numbers->max() ?? 0,
        };

        return ['function' => $function, 'column_id' => (string) $column->id, 'value' => round((float) $value, 2), 'item_count' => $items->count()];
    }

    /**
     * How far along the work is: the share of items whose status is a
     * "done" label, next to the full status breakdown.
     *
     * @param  Collection<int, BoardColumn>  $columns
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function battery(BoardView $view, Collection $columns, array $settings): array
    {
        $overview = $this->statusOverview($view, $columns, $settings);
        $done_ids = collect($overview['segments'])->filter(fn (array $segment) => $segment['is_done'])->pluck('id');
        $done_count = collect($overview['segments'])->filter(fn (array $segment) => $segment['is_done'])->sum('count');

        return $overview + [
            'done_count' => $done_count,
            'done_percent' => $overview['total'] > 0 ? round($done_count / $overview['total'] * 100, 1) : 0,
            'done_option_ids' => $done_ids->values()->all(),
        ];
    }

    /**
     * Items per option of a Status, Label or Dropdown column, in the
     * column's own option order, plus the items with no value.
     *
     * @param  Collection<int, BoardColumn>  $columns
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function statusOverview(BoardView $view, Collection $columns, array $settings): array
    {
        $option_columns = $columns->whereIn('type', self::OPTION_COLUMN_TYPES);
        $column = isset($settings['status_column_id']) ? $option_columns->firstWhere('id', (int) $settings['status_column_id']) : null;
        $column ??= $option_columns->firstWhere('type', BoardColumn::TYPE_STATUS) ?? $option_columns->first();

        $items = $this->source->rootItems($view);
        if ($column === null) {
            return ['status_column_id' => null, 'total' => $items->count(), 'segments' => []];
        }

        $done_option_ids = is_array($settings['done_option_ids'] ?? null) ? array_map('strval', $settings['done_option_ids']) : null;
        $counts = [];
        $empty_count = 0;
        foreach ($items as $item) {
            $raw = $this->source->rawValue($item, $column);
            $ids = is_array($raw) ? array_map('strval', $raw) : ($raw === null || $raw === '' ? [] : [(string) $raw]);
            if ($ids === []) {
                $empty_count++;
            }
            foreach ($ids as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        $total = $items->count();
        $segments = [];
        foreach ($column->config['options'] ?? [] as $option) {
            if (! is_array($option) || ! isset($option['id'])) {
                continue;
            }
            $id = (string) $option['id'];
            $count = $counts[$id] ?? 0;
            $resolved = ['id' => $id, 'label' => (string) ($option['label'] ?? ''), 'color' => (string) ($option['color'] ?? '#c4c4c4')];
            $segments[] = $resolved + [
                'count' => $count,
                'percent' => $total > 0 ? round($count / $total * 100, 1) : 0,
                'is_done' => $done_option_ids !== null ? in_array($id, $done_option_ids, true) : $this->source->isDoneOption($resolved),
            ];
        }
        if ($empty_count > 0) {
            $segments[] = [
                'id' => '__none__',
                'label' => 'No value',
                'color' => '#c4c4c4',
                'count' => $empty_count,
                'percent' => $total > 0 ? round($empty_count / $total * 100, 1) : 0,
                'is_done' => false,
            ];
        }

        return ['status_column_id' => (string) $column->id, 'total' => $total, 'segments' => $segments];
    }

    /**
     * Reuses the Chart tab's engine with the widget's own settings, through
     * an unsaved view that only carries `chart_config`.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function chart(WorkspaceNavigationItem $board, BoardView $source_view, array $settings): array
    {
        $chart_view = new BoardView;
        $chart_view->chart_config = [
            'chart_type' => $settings['chart_type'] ?? 'bar',
            'source_view_id' => $source_view->id,
            'group_by_column_id' => $settings['group_by_column_id'] ?? null,
            'split_by_column_id' => $settings['split_by_column_id'] ?? null,
            'aggregate_fn' => $settings['aggregate_fn'] ?? 'count',
            'value_column_id' => $settings['value_column_id'] ?? null,
            'date_bucket' => $settings['date_bucket'] ?? null,
        ];

        return $this->chart_service->build($board, $chart_view);
    }

    /**
     * A compact list of items with up to five columns, as display text.
     *
     * @param  Collection<int, BoardColumn>  $columns
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function table(BoardView $view, Collection $columns, array $settings): array
    {
        $limit = max(1, min(self::TABLE_MAX_LIMIT, (int) ($settings['limit'] ?? self::TABLE_DEFAULT_LIMIT)));
        $requested_ids = is_array($settings['column_ids'] ?? null) ? array_map('intval', $settings['column_ids']) : [];
        $shown_columns = $requested_ids !== []
            ? collect($requested_ids)->map(fn (int $id) => $columns->firstWhere('id', $id))->filter()->values()
            : $columns->reject(fn (BoardColumn $column) => in_array($column->type, [BoardColumn::TYPE_FILES, BoardColumn::TYPE_FORMULA, BoardColumn::TYPE_MIRROR], true))->values();
        $shown_columns = $shown_columns->take(self::TABLE_MAX_COLUMNS);

        $items = $this->source->rootItems($view);
        $page = $items->take($limit);

        $people_ids = [];
        foreach ($shown_columns->where('type', BoardColumn::TYPE_PEOPLE) as $column) {
            foreach ($page as $item) {
                $people_ids = array_merge($people_ids, $this->source->peopleIds($this->source->rawValue($item, $column)));
            }
        }
        $people = $this->source->people(array_values(array_unique($people_ids)));
        $tags = $shown_columns->contains('type', BoardColumn::TYPE_TAGS)
            ? BoardTag::where('board_id', $view->board_id)->get(['id', 'label', 'color'])->keyBy('id')
            : collect();

        return [
            'columns' => $this->source->columnOptions($shown_columns),
            'rows' => $page->map(fn (BoardItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'group_name' => $item->group?->name,
                'group_color' => $item->group?->accent_color,
                'cells' => $shown_columns->map(fn (BoardColumn $column) => $this->displayCell($column, $this->source->rawValue($item, $column), $people, $tags))->values()->all(),
            ])->values()->all(),
            'total' => $items->count(),
        ];
    }

    /**
     * Items (or effort) per person in a People column, busiest first.
     *
     * @param  Collection<int, BoardColumn>  $columns
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function workload(BoardView $view, Collection $columns, array $settings): array
    {
        $people_columns = $columns->where('type', BoardColumn::TYPE_PEOPLE);
        $people_column = isset($settings['people_column_id']) ? $people_columns->firstWhere('id', (int) $settings['people_column_id']) : null;
        $people_column ??= $people_columns->first();
        $effort_column = isset($settings['effort_column_id'])
            ? $columns->where('type', BoardColumn::TYPE_NUMBER)->firstWhere('id', (int) $settings['effort_column_id'])
            : null;

        if ($people_column === null) {
            return ['people_column_id' => null, 'effort_column_id' => null, 'people' => [], 'unassigned' => 0];
        }

        $loads = [];
        $counts = [];
        $unassigned = 0;
        foreach ($this->source->rootItems($view) as $item) {
            $ids = $this->source->peopleIds($this->source->rawValue($item, $people_column));
            if ($ids === []) {
                $unassigned++;

                continue;
            }
            $effort = $effort_column ? ($this->source->numberValue($this->source->rawValue($item, $effort_column)) ?? 0.0) : 1.0;
            foreach ($ids as $id) {
                $loads[$id] = ($loads[$id] ?? 0) + $effort;
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        arsort($loads);
        $top_ids = array_slice(array_keys($loads), 0, self::WORKLOAD_MAX_PEOPLE);
        $people = $this->source->people($top_ids);

        return [
            'people_column_id' => (string) $people_column->id,
            'effort_column_id' => $effort_column ? (string) $effort_column->id : null,
            'people' => collect($top_ids)
                ->filter(fn (int $id) => $people->has($id))
                ->map(fn (int $id) => ['person' => $people->get($id), 'load' => round($loads[$id], 2), 'item_count' => $counts[$id]])
                ->values()
                ->all(),
            'unassigned' => $unassigned,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $people
     * @param  Collection<int, BoardTag>  $tags
     * @return array{text: string, color: string|null}
     */
    private function displayCell(BoardColumn $column, mixed $value, Collection $people, Collection $tags): array
    {
        $empty = ['text' => '', 'color' => null];
        if ($value === null || $value === '' || $value === []) {
            return $empty;
        }

        switch ($column->type) {
            case BoardColumn::TYPE_STATUS:
            case BoardColumn::TYPE_LABEL:
                $option = $this->source->option($column, $value);

                return $option ? ['text' => $option['label'], 'color' => $option['color']] : $empty;
            case BoardColumn::TYPE_DROPDOWN:
                $labels = collect((array) $value)->map(fn ($id) => $this->source->option($column, $id)['label'] ?? null)->filter();

                return ['text' => $labels->implode(', '), 'color' => null];
            case BoardColumn::TYPE_TAGS:
                return ['text' => collect((array) $value)->map(fn ($id) => $tags->get((int) $id)?->label)->filter()->implode(', '), 'color' => null];
            case BoardColumn::TYPE_PEOPLE:
                return ['text' => collect($this->source->peopleIds($value))->map(fn (int $id) => $people->get($id)['name'] ?? null)->filter()->implode(', '), 'color' => null];
            case BoardColumn::TYPE_DATE:
            case BoardColumn::TYPE_TIMELINE:
                $range = $this->source->dateRange($value);
                if ($range === null) {
                    return $empty;
                }

                return ['text' => $range[0]->eq($range[1]) ? $range[0]->format('M j, Y') : $range[0]->format('M j').' to '.$range[1]->format('M j, Y'), 'color' => null];
            case BoardColumn::TYPE_CHECKBOX:
                return ['text' => $value === true ? 'Yes' : 'No', 'color' => null];
            case BoardColumn::TYPE_LINK:
                return ['text' => is_array($value) ? (string) ($value['text'] ?? $value['url'] ?? '') : (string) $value, 'color' => null];
            case BoardColumn::TYPE_NUMBER:
            case BoardColumn::TYPE_RATING:
            case BoardColumn::TYPE_TEXT:
            case BoardColumn::TYPE_LONG_TEXT:
            case BoardColumn::TYPE_EMAIL:
            case BoardColumn::TYPE_PHONE:
            case BoardColumn::TYPE_AUTO_NUMBER:
                return is_scalar($value) ? ['text' => (string) $value, 'color' => null] : $empty;
            default:
                return $empty;
        }
    }
}
