<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds a `workload`-type tab: how much work each person has per day or per
 * week, read from another tab of the board (like a Chart tab, a Workload tab
 * holds no items of its own). An item counts for everyone in its People
 * column, on the days of its Date or Timeline column. Its effort is the
 * chosen Number column, or 1 per item when none is chosen, and an item that
 * spans several days spreads its effort evenly over them, like monday.com's
 * Workload view. Each cell is compared with the person's capacity so the
 * frontend can color it as under, at or over capacity.
 */
class WorkloadDataService
{
    /** How many columns (days or weeks) one screen shows. */
    public const BUCKET_COUNT = ['day' => 14, 'week' => 8];

    /** Capacity per bucket when none is configured: items when counting, hours when an effort column is set. */
    private const DEFAULT_CAPACITY = [
        'count' => ['day' => 3, 'week' => 10],
        'effort' => ['day' => 8, 'week' => 40],
    ];

    /** Items listed per person, enough for the cells and their popovers. */
    private const MAX_ITEMS_PER_PERSON = 200;

    public function __construct(private readonly BoardViewDataSource $source) {}

    /**
     * @return array<string, mixed>
     */
    public function build(WorkspaceNavigationItem $board, BoardView $workload_view, ?string $start = null): array
    {
        $config = $workload_view->workload_config ?? [];
        $bucket = in_array($config['bucket'] ?? null, ['day', 'week'], true) ? $config['bucket'] : 'week';
        $range_start = $this->rangeStart($start, $bucket);
        $buckets = $this->buckets($range_start, $bucket);
        $range_end = Carbon::parse(end($buckets)['end']);

        $source_view = $this->source->resolveSourceView($board, isset($config['source_view_id']) ? (int) $config['source_view_id'] : null);
        $base = [
            'source_views' => $this->source->sourceViewOptions($board),
            'buckets' => $buckets,
            'range' => [
                'start' => $range_start->toDateString(),
                'end' => $range_end->toDateString(),
                'previous_start' => $this->shift($range_start, $bucket, -1)->toDateString(),
                'next_start' => $this->shift($range_start, $bucket, 1)->toDateString(),
            ],
        ];

        if ($source_view === null) {
            return $base + [
                'config' => $this->resolvedConfig($config, null, null, null, null, $bucket),
                'people_columns' => [],
                'date_columns' => [],
                'effort_columns' => [],
                'people' => [],
                'unassigned' => null,
                'has_data' => false,
            ];
        }

        $people_columns = $this->source->columns($source_view, [BoardColumn::TYPE_PEOPLE]);
        $date_columns = $this->source->columns($source_view, [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE]);
        $effort_columns = $this->source->columns($source_view, [BoardColumn::TYPE_NUMBER]);

        $people_column = $this->pick($people_columns, $config['people_column_id'] ?? null);
        $date_column = $this->pick($date_columns, $config['date_column_id'] ?? null);
        $effort_column = isset($config['effort_column_id']) && $config['effort_column_id'] !== null
            ? $effort_columns->firstWhere('id', (int) $config['effort_column_id'])
            : null;

        $resolved_config = $this->resolvedConfig($config, $source_view, $people_column, $date_column, $effort_column, $bucket);

        $rows = $people_column && $date_column
            ? $this->rows($this->source->rootItems($source_view), $people_column, $date_column, $effort_column, $buckets, $range_start, $range_end, $resolved_config)
            : ['people' => [], 'unassigned' => null];

        return $base + [
            'config' => $resolved_config,
            'people_columns' => $this->source->columnOptions($people_columns),
            'date_columns' => $this->source->columnOptions($date_columns),
            'effort_columns' => $this->source->columnOptions($effort_columns),
            'people' => $rows['people'],
            'unassigned' => $rows['unassigned'],
            'has_data' => $rows['people'] !== [] || $rows['unassigned'] !== null,
        ];
    }

    /**
     * @param  Collection<int, BoardItem>  $items
     * @param  array<int, array<string, mixed>>  $buckets
     * @param  array<string, mixed>  $config
     * @return array{people: array<int, array<string, mixed>>, unassigned: array<string, mixed>|null}
     */
    private function rows(
        Collection $items,
        BoardColumn $people_column,
        BoardColumn $date_column,
        ?BoardColumn $effort_column,
        array $buckets,
        Carbon $range_start,
        Carbon $range_end,
        array $config,
    ): array {
        /** @var array<string, array{loads: array<string, float>, items: array<int, array<string, mixed>>, unscheduled: int, total: float}> $by_person */
        $by_person = [];

        foreach ($items as $item) {
            $person_ids = $this->source->peopleIds($this->source->rawValue($item, $people_column));
            $keys = $person_ids === [] ? ['none'] : array_map('strval', $person_ids);
            $effort = $effort_column ? ($this->source->numberValue($this->source->rawValue($item, $effort_column)) ?? 0.0) : 1.0;
            $range = $this->source->dateRange($this->source->rawValue($item, $date_column));

            foreach ($keys as $key) {
                $by_person[$key] ??= ['loads' => [], 'items' => [], 'unscheduled' => 0, 'total' => 0.0];

                if ($range === null) {
                    $by_person[$key]['unscheduled']++;

                    continue;
                }

                [$item_start, $item_end] = $range;
                if ($item_end->lt($range_start) || $item_start->gt($range_end)) {
                    continue;
                }

                $item_loads = $this->spread($effort, $item_start, $item_end, $buckets);
                foreach ($item_loads as $bucket_key => $load) {
                    $by_person[$key]['loads'][$bucket_key] = ($by_person[$key]['loads'][$bucket_key] ?? 0) + $load;
                }
                $by_person[$key]['total'] += array_sum($item_loads);

                if (count($by_person[$key]['items']) < self::MAX_ITEMS_PER_PERSON) {
                    $by_person[$key]['items'][] = [
                        'id' => $item->id,
                        'name' => $item->name,
                        'group_name' => $item->group?->name,
                        'group_color' => $item->group?->accent_color,
                        'start' => $item_start->toDateString(),
                        'end' => $item_end->toDateString(),
                        'effort' => $effort,
                        'bucket_keys' => array_keys($item_loads),
                        'person_ids' => $person_ids,
                    ];
                }
            }
        }

        $people = $this->source->people(array_map('intval', array_filter(array_keys($by_person), fn ($key) => $key !== 'none')));
        $overrides = is_array($config['capacity_overrides'] ?? null) ? $config['capacity_overrides'] : [];

        $rows = [];
        foreach ($people as $person_id => $person) {
            $capacity = isset($overrides[(string) $person_id]) ? (float) $overrides[(string) $person_id] : (float) $config['capacity'];
            $rows[] = $this->row($person, $by_person[(string) $person_id], $buckets, $capacity);
        }
        usort($rows, fn (array $a, array $b) => strcasecmp($a['person']['name'], $b['person']['name']));

        $unassigned = isset($by_person['none'])
            ? $this->row(['id' => null, 'name' => 'Unassigned', 'photo_url' => null, 'is_deactivated' => false], $by_person['none'], $buckets, null)
            : null;

        return ['people' => $rows, 'unassigned' => $unassigned];
    }

    /**
     * @param  array<string, mixed>  $person
     * @param  array{loads: array<string, float>, items: array<int, array<string, mixed>>, unscheduled: int, total: float}  $data
     * @param  array<int, array<string, mixed>>  $buckets
     * @return array<string, mixed>
     */
    private function row(array $person, array $data, array $buckets, ?float $capacity): array
    {
        return [
            'person' => $person,
            'capacity' => $capacity,
            'total' => round($data['total'], 2),
            'unscheduled_count' => $data['unscheduled'],
            'cells' => array_map(fn (array $bucket) => [
                'key' => $bucket['key'],
                'load' => round($data['loads'][$bucket['key']] ?? 0, 2),
            ], $buckets),
            'items' => $data['items'],
        ];
    }

    /**
     * Spreads an item's effort evenly over each day it spans, then sums the
     * days that fall into each visible bucket.
     *
     * @param  array<int, array<string, mixed>>  $buckets
     * @return array<string, float> bucket key => load
     */
    private function spread(float $effort, Carbon $start, Carbon $end, array $buckets): array
    {
        $day_count = $start->diffInDays($end) + 1;
        $per_day = $effort / max(1, $day_count);
        $loads = [];

        foreach ($buckets as $bucket) {
            $bucket_start = Carbon::parse($bucket['start']);
            $bucket_end = Carbon::parse($bucket['end']);
            $overlap_start = $start->gt($bucket_start) ? $start : $bucket_start;
            $overlap_end = $end->lt($bucket_end) ? $end : $bucket_end;
            if ($overlap_start->gt($overlap_end)) {
                continue;
            }

            $loads[$bucket['key']] = $per_day * ($overlap_start->diffInDays($overlap_end) + 1);
        }

        return $loads;
    }

    /**
     * @return array<int, array{key: string, label: string, start: string, end: string, is_current: bool}>
     */
    private function buckets(Carbon $range_start, string $bucket): array
    {
        $today = Carbon::today();
        $buckets = [];
        $cursor = $range_start->copy();

        for ($index = 0; $index < self::BUCKET_COUNT[$bucket]; $index++) {
            $end = $bucket === 'day' ? $cursor->copy() : $cursor->copy()->addDays(6);
            $buckets[] = [
                'key' => $cursor->toDateString(),
                'label' => $bucket === 'day' ? $cursor->format('D j') : $cursor->format('M j'),
                'start' => $cursor->toDateString(),
                'end' => $end->toDateString(),
                'is_current' => $today->betweenIncluded($cursor, $end),
                'is_weekend' => $bucket === 'day' && $cursor->isWeekend(),
            ];
            $cursor = $bucket === 'day' ? $cursor->addDay() : $cursor->addWeek();
        }

        return $buckets;
    }

    /** The first bucket shown: the requested date, snapped to a Monday for weeks, or this week's Monday. */
    private function rangeStart(?string $start, string $bucket): Carbon
    {
        try {
            $date = $start ? Carbon::parse($start)->startOfDay() : Carbon::today();
        } catch (\Throwable) {
            $date = Carbon::today();
        }

        return $bucket === 'week' || $start === null ? $date->startOfWeek(Carbon::MONDAY) : $date;
    }

    private function shift(Carbon $range_start, string $bucket, int $direction): Carbon
    {
        $step = self::BUCKET_COUNT[$bucket];

        return $bucket === 'day'
            ? $range_start->copy()->addDays($direction * $step)
            : $range_start->copy()->addWeeks($direction * $step);
    }

    /**
     * @param  Collection<int, BoardColumn>  $columns
     */
    private function pick(Collection $columns, mixed $configured_id): ?BoardColumn
    {
        if ($configured_id !== null && $configured_id !== '') {
            $configured = $columns->firstWhere('id', (int) $configured_id);
            if ($configured) {
                return $configured;
            }
        }

        return $columns->first();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function resolvedConfig(array $config, ?BoardView $source_view, ?BoardColumn $people_column, ?BoardColumn $date_column, ?BoardColumn $effort_column, string $bucket): array
    {
        $default_capacity = self::DEFAULT_CAPACITY[$effort_column ? 'effort' : 'count'][$bucket];

        return [
            'source_view_id' => $source_view?->id,
            'people_column_id' => $people_column ? (string) $people_column->id : null,
            'date_column_id' => $date_column ? (string) $date_column->id : null,
            'effort_column_id' => $effort_column ? (string) $effort_column->id : null,
            'bucket' => $bucket,
            'capacity' => isset($config['capacity']) && is_numeric($config['capacity']) ? (float) $config['capacity'] : (float) $default_capacity,
            'capacity_overrides' => is_array($config['capacity_overrides'] ?? null) ? $config['capacity_overrides'] : (object) [],
        ];
    }
}
