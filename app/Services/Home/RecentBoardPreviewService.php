<?php

namespace App\Services\Home;

use App\Enums\BoardViewType;
use App\Models\BoardColumn;
use App\Models\BoardDoc;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\BoardView;
use App\Models\BoardViewFile;
use App\Models\BoardVisit;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\ChartDataService;
use App\Support\DocOutline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The small snapshot the Home page draws on each "Recently visited" card.
 *
 * Every card previews the tab the user last had open (or the board's primary
 * tab), shaped for that tab's kind: a Table sends its groups with sample rows
 * and real cell colors, a Kanban its lanes and cards, a Gantt its timeline
 * bars, a Calendar the month with its events, a Chart its real numbers, a Doc
 * its outline, and so on. The frontend turns each shape into a mini view.
 *
 * Item based tabs are loaded for every board at once with a fixed number of
 * queries, so the Home page stays fast however many boards it lists.
 */
class RecentBoardPreviewService
{
    /** How many groups a card draws. */
    public const GROUP_LIMIT = 4;

    /** Sample rows per group on a Table preview. */
    private const ROWS_PER_GROUP = 3;

    /** Cells a Table preview row draws next to the item name. */
    private const TABLE_COLUMN_LIMIT = 3;

    private const LANE_LIMIT = 4;

    private const CARDS_PER_LANE = 3;

    private const GANTT_BAR_LIMIT = 6;

    private const CALENDAR_EVENT_LIMIT = 40;

    private const CHART_POINT_LIMIT = 6;

    private const CHART_SERIES_LIMIT = 3;

    private const FORM_QUESTION_LIMIT = 4;

    private const FILE_LIMIT = 6;

    private const WORKLOAD_PEOPLE_LIMIT = 4;

    /** Column kinds a Table preview row can draw as a real cell. */
    private const DRAWABLE_COLUMN_TYPES = [
        BoardColumn::TYPE_STATUS,
        BoardColumn::TYPE_LABEL,
        BoardColumn::TYPE_PEOPLE,
        BoardColumn::TYPE_DATE,
        BoardColumn::TYPE_TIMELINE,
        BoardColumn::TYPE_PROGRESS,
        BoardColumn::TYPE_CHECKBOX,
        BoardColumn::TYPE_NUMBER,
        BoardColumn::TYPE_DROPDOWN,
        BoardColumn::TYPE_TAGS,
        BoardColumn::TYPE_TEXT,
    ];

    /** Tabs whose preview is drawn from the board's items. */
    private const ITEM_KINDS = ['table', 'kanban', 'gantt', 'calendar', 'workload'];

    public function __construct(private readonly ChartDataService $charts) {}

    /**
     * @param  Collection<int, BoardVisit>  $visits  each with its `board` loaded
     * @return array<int, array<string, mixed>> keyed by board id
     */
    public function previewsFor(Collection $visits): array
    {
        if ($visits->isEmpty()) {
            return [];
        }

        $board_ids = $visits->map(fn (BoardVisit $visit) => (int) $visit->board_id)->unique()->values()->all();

        $views_by_board = BoardView::query()
            ->whereIn('board_id', $board_ids)
            ->orderBy('position')
            ->get(['id', 'board_id', 'label', 'view_type', 'source_view_id', 'is_primary', 'position', 'chart_config', 'form_config', 'hidden_column_ids'])
            ->groupBy('board_id');

        // Which tab each card previews, and the kind of preview that tab gets.
        $targets = $visits->mapWithKeys(function (BoardVisit $visit) use ($views_by_board) {
            /** @var Collection<int, BoardView> $views */
            $views = $views_by_board->get($visit->board_id, collect());
            $primary_view = $views->firstWhere('is_primary', true) ?? $views->first();
            $view = $views->firstWhere('id', $visit->board_view_id) ?? $primary_view;

            return [(int) $visit->board_id => [
                'board' => $visit->board,
                'kind' => $this->kindOf($visit->board, $view),
                'view' => $view,
                'primary_view' => $primary_view,
            ]];
        });

        // Item data is shared by the item based tabs and by every card's item count.
        $content_view_ids = $targets
            ->flatMap(fn (array $target) => array_filter([$target['view']?->content_view_id, $target['primary_view']?->content_view_id]))
            ->unique()
            ->values()
            ->all();
        $item_data = $this->loadItemData($content_view_ids);

        return $targets
            ->map(fn (array $target) => $this->previewOf($target, $item_data))
            ->all();
    }

    /**
     * @param  array{board: WorkspaceNavigationItem, kind: string, view: BoardView|null, primary_view: BoardView|null}  $target
     * @param  array<int, array{groups: Collection<int, BoardGroup>, columns: Collection<int, BoardColumn>, items: Collection<int, Collection<int, BoardItem>>, values: array<int, array<int, mixed>>}>  $item_data
     * @return array<string, mixed>
     */
    private function previewOf(array $target, array $item_data): array
    {
        ['board' => $board, 'kind' => $kind, 'view' => $view, 'primary_view' => $primary_view] = $target;

        $primary_data = $primary_view ? ($item_data[$primary_view->content_view_id] ?? null) : null;
        // A Workload tab keeps no items of its own, it reads the board's main data.
        $view_data = $kind === 'workload' ? $primary_data : ($view ? ($item_data[$view->content_view_id] ?? null) : null);
        $count_data = (in_array($kind, self::ITEM_KINDS, true) ? $view_data : null) ?? $primary_data;

        $preview = [
            'kind' => $kind,
            'view_label' => $view && ! in_array($kind, ['doc_page', 'workspace'], true) ? $view->label : null,
            'item_count' => $count_data ? $count_data['items']->sum(fn (Collection $items) => $items->count()) : 0,
            'group_count' => $count_data ? $count_data['groups']->count() : 0,
            'groups' => $count_data ? $this->groupSummaries($count_data) : [],
        ];

        return $preview + match ($kind) {
            'table' => $view_data ? ['table' => $this->tablePreview($view, $view_data)] : [],
            'kanban' => $view_data ? ['kanban' => $this->kanbanPreview($view_data)] : [],
            'gantt' => $view_data ? ['gantt' => $this->ganttPreview($view_data)] : [],
            'calendar' => $view_data ? ['calendar' => $this->calendarPreview($view_data)] : [],
            'workload' => $view_data ? ['workload' => $this->workloadPreview($view_data)] : [],
            'chart' => ['chart' => $this->chartPreview($board, $view)],
            'form' => ['form' => $this->formPreview($board, $view, $primary_view)],
            'file_gallery' => ['files' => $this->filesPreview($view)],
            'doc' => ['doc' => DocOutline::fromContent(BoardView::query()->whereKey($view->id)->value('doc_content'))],
            'doc_page' => ['doc' => DocOutline::fromContent(BoardDoc::query()->where('board_id', $board->id)->value('content'))],
            'workspace' => ['workspace' => $this->workspacePreview($board)],
            default => [],
        };
    }

    /**
     * The preview kind: a Doc leaf and Manage Workspace have no tabs, every
     * other board previews its tab's own kind.
     */
    private function kindOf(WorkspaceNavigationItem $board, ?BoardView $view): string
    {
        if ($board->view_key === 'doc') {
            return 'doc_page';
        }
        if ($board->view_key === 'workspace_manage') {
            return 'workspace';
        }
        if ($view === null) {
            return 'table';
        }

        return in_array($view->view_type, [...BoardViewType::values(), 'workload'], true) ? $view->view_type : 'table';
    }

    // ── Item data ──────────────────────────────────────────────────────────

    /**
     * Groups, root columns, items (per group) and the drawable cell values of
     * every given content tab, in four queries.
     *
     * @param  array<int, int>  $content_view_ids
     * @return array<int, array{groups: Collection<int, BoardGroup>, columns: Collection<int, BoardColumn>, items: Collection<int, Collection<int, BoardItem>>, values: array<int, array<int, mixed>>}>
     */
    private function loadItemData(array $content_view_ids): array
    {
        if ($content_view_ids === []) {
            return [];
        }

        $groups = BoardGroup::query()
            ->whereIn('board_view_id', $content_view_ids)
            ->where('is_archived', false)
            ->orderBy('position')
            ->get(['id', 'board_id', 'board_view_id', 'name', 'accent_color']);

        $columns = BoardColumn::query()
            ->whereIn('board_view_id', $content_view_ids)
            ->where('scope', BoardColumn::SCOPE_ITEM)
            ->orderBy('position')
            ->get(['id', 'board_view_id', 'label', 'type', 'config']);

        $items = BoardItem::query()
            ->whereIn('group_id', $groups->pluck('id'))
            ->whereNull('parent_id')
            ->where('is_archived', false)
            ->orderBy('position')
            ->get(['id', 'group_id', 'name'])
            ->groupBy('group_id');

        $item_ids = $items->flatten()->pluck('id')->flip();
        $values = [];
        BoardItemValue::query()
            ->whereIn('column_id', $columns->whereIn('type', self::DRAWABLE_COLUMN_TYPES)->pluck('id'))
            ->get(['item_id', 'column_id', 'value'])
            ->each(function (BoardItemValue $value) use (&$values, $item_ids) {
                if ($item_ids->has($value->item_id)) {
                    $values[$value->item_id][$value->column_id] = $value->value;
                }
            });

        $groups_by_view = $groups->groupBy('board_view_id');
        $columns_by_view = $columns->groupBy('board_view_id');

        return collect($content_view_ids)->mapWithKeys(function (int $view_id) use ($groups_by_view, $columns_by_view, $items, $values) {
            /** @var Collection<int, BoardGroup> $view_groups */
            $view_groups = $groups_by_view->get($view_id, collect())->values();
            $view_items = $view_groups->mapWithKeys(fn (BoardGroup $group) => [$group->id => $items->get($group->id, collect())->values()]);

            return [$view_id => [
                'groups' => $view_groups,
                'columns' => $columns_by_view->get($view_id, collect())->values(),
                'items' => $view_items,
                'values' => $values,
            ]];
        })->all();
    }

    /**
     * @return array<int, array{id: int, name: string, color: string|null, item_count: int}>
     */
    private function groupSummaries(array $data): array
    {
        return $data['groups']
            ->take(self::GROUP_LIMIT)
            ->map(fn (BoardGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'color' => $group->accent_color,
                'item_count' => $data['items']->get($group->id, collect())->count(),
            ])
            ->values()
            ->all();
    }

    // ── Table ──────────────────────────────────────────────────────────────

    /**
     * The first drawable columns of the tab and a few real rows per group,
     * each cell carrying its real color (Status, Label) or whether it is set.
     *
     * @return array{columns: array<int, string>, rows: array<int, array<int, array{name_ratio: float, cells: array<int, array{color: string|null, filled: bool}>}>>}
     */
    private function tablePreview(BoardView $view, array $data): array
    {
        $hidden_ids = array_map('strval', $view->hidden_column_ids ?? []);
        $columns = $data['columns']
            ->filter(fn (BoardColumn $column) => in_array($column->type, self::DRAWABLE_COLUMN_TYPES, true) && ! in_array((string) $column->id, $hidden_ids, true))
            ->take(self::TABLE_COLUMN_LIMIT)
            ->values();

        $rows = $data['groups']->take(self::GROUP_LIMIT)->mapWithKeys(fn (BoardGroup $group) => [
            $group->id => $data['items']->get($group->id, collect())
                ->take(self::ROWS_PER_GROUP)
                ->map(fn (BoardItem $item) => [
                    'name_ratio' => $this->nameRatio($item->name),
                    'cells' => $columns->map(fn (BoardColumn $column) => $this->cellOf($column, $data['values'][$item->id][$column->id] ?? null))->all(),
                ])
                ->values()
                ->all(),
        ]);

        return [
            'columns' => $columns->map(fn (BoardColumn $column) => $column->type)->all(),
            'rows' => $rows->all(),
        ];
    }

    /**
     * @return array{color: string|null, filled: bool}
     */
    private function cellOf(BoardColumn $column, mixed $value): array
    {
        $is_filled = match (true) {
            $value === null, $value === '', $value === [] => false,
            $column->type === BoardColumn::TYPE_CHECKBOX => (bool) $value,
            default => true,
        };

        $color = in_array($column->type, [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL], true) && $is_filled
            ? $this->optionColor($column, $value)
            : null;

        return ['color' => $color, 'filled' => $is_filled];
    }

    // ── Kanban ─────────────────────────────────────────────────────────────

    /**
     * Lanes are the first Status column's options, exactly like the Kanban
     * tab itself. A board without a Status column falls back to its groups.
     *
     * @return array{lanes: array<int, array{label: string, color: string, item_count: int, cards: array<int, array{name_ratio: float, people_count: int, has_date: bool}>}>}
     */
    private function kanbanPreview(array $data): array
    {
        $status_column = $data['columns']->firstWhere('type', BoardColumn::TYPE_STATUS);
        $people_column = $data['columns']->firstWhere('type', BoardColumn::TYPE_PEOPLE);
        $date_column = $data['columns']->first(fn (BoardColumn $column) => in_array($column->type, [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE], true));
        $all_items = $data['items']->flatten(1);

        $cardOf = fn (BoardItem $item) => [
            'name_ratio' => $this->nameRatio($item->name),
            'people_count' => $people_column ? count((array) ($data['values'][$item->id][$people_column->id] ?? [])) : 0,
            'has_date' => $date_column !== null && ! empty($data['values'][$item->id][$date_column->id] ?? null),
        ];

        if ($status_column === null) {
            $lanes = $data['groups']->take(self::LANE_LIMIT)->map(fn (BoardGroup $group) => [
                'label' => $group->name,
                'color' => $group->accent_color ?? '#c4c4c4',
                'items' => $data['items']->get($group->id, collect()),
            ]);
        } else {
            $items_by_status = $all_items->groupBy(fn (BoardItem $item) => (string) ($data['values'][$item->id][$status_column->id] ?? ''));
            $lanes = collect($status_column->config['options'] ?? [])
                ->take(self::LANE_LIMIT)
                ->map(fn (array $option) => [
                    'label' => (string) ($option['label'] ?? ''),
                    'color' => (string) ($option['color'] ?? '#c4c4c4'),
                    'items' => $items_by_status->get((string) ($option['id'] ?? ''), collect()),
                ]);
        }

        return [
            'lanes' => $lanes->map(fn (array $lane) => [
                'label' => $lane['label'],
                'color' => $lane['color'],
                'item_count' => $lane['items']->count(),
                'cards' => $lane['items']->take(self::CARDS_PER_LANE)->map($cardOf)->values()->all(),
            ])->values()->all(),
        ];
    }

    // ── Gantt ──────────────────────────────────────────────────────────────

    /**
     * Real timeline bars, placed as fractions (0 to 1) of the span they
     * cover. A Date column gives one day milestones. Empty when nothing is
     * scheduled yet, the frontend then draws a sample plan in group colors.
     *
     * @return array{bars: array<int, array{start: float, end: float, color: string, is_milestone: bool}>, today: float|null}
     */
    private function ganttPreview(array $data): array
    {
        $column = $data['columns']->firstWhere('type', BoardColumn::TYPE_TIMELINE)
            ?? $data['columns']->firstWhere('type', BoardColumn::TYPE_DATE);
        if ($column === null) {
            return ['bars' => [], 'today' => null];
        }

        $spans = $this->datedItems($data, $column)
            ->sortBy('start')
            ->take(self::GANTT_BAR_LIMIT)
            ->values();
        if ($spans->isEmpty()) {
            return ['bars' => [], 'today' => null];
        }

        $range_start = $spans->min(fn (array $span) => $span['start']->getTimestamp());
        $range_end = max($spans->max(fn (array $span) => $span['end']->copy()->endOfDay()->getTimestamp()), $range_start + 86400);
        $length = $range_end - $range_start;
        $fractionOf = fn (int $timestamp) => round(min(1, max(0, ($timestamp - $range_start) / $length)), 3);

        $now = now()->getTimestamp();

        return [
            'bars' => $spans->map(fn (array $span) => [
                'start' => $fractionOf($span['start']->getTimestamp()),
                'end' => $fractionOf($span['end']->copy()->endOfDay()->getTimestamp()),
                'color' => $span['color'],
                'is_milestone' => $span['start']->isSameDay($span['end']),
            ])->all(),
            'today' => $now >= $range_start && $now <= $range_end ? $fractionOf($now) : null,
        ];
    }

    // ── Calendar ───────────────────────────────────────────────────────────

    /**
     * One month of events: this month when it has any, otherwise the busiest
     * month, so a card never shows an empty calendar while the board has dates.
     *
     * @return array{year: int, month: int, first_weekday: int, day_count: int, today: int|null, events: array<int, array{day: int, color: string}>}
     */
    private function calendarPreview(array $data): array
    {
        $column = $data['columns']->firstWhere('type', BoardColumn::TYPE_DATE)
            ?? $data['columns']->firstWhere('type', BoardColumn::TYPE_TIMELINE);
        $dated = $column ? $this->datedItems($data, $column) : collect();

        $now = now();
        $month_of = fn (array $span) => $span['start']->format('Y-m');
        $by_month = $dated->groupBy($month_of);
        $month_key = $by_month->has($now->format('Y-m'))
            ? $now->format('Y-m')
            : ($by_month->sortByDesc(fn (Collection $events) => $events->count())->keys()->first() ?? $now->format('Y-m'));

        $month_start = Carbon::createFromFormat('Y-m-d', $month_key.'-01')->startOfDay();

        return [
            'year' => $month_start->year,
            'month' => $month_start->month,
            'first_weekday' => $month_start->dayOfWeek,
            'day_count' => $month_start->daysInMonth,
            'today' => $month_start->isSameMonth($now) ? $now->day : null,
            'events' => $by_month->get($month_key, collect())
                ->take(self::CALENDAR_EVENT_LIMIT)
                ->map(fn (array $span) => ['day' => $span['start']->day, 'color' => $span['color']])
                ->values()
                ->all(),
        ];
    }

    /**
     * Every item with a readable value in `$column`, as a start and end day
     * and the color it is drawn in: its first Status color, else its group's.
     *
     * @return Collection<int, array{start: Carbon, end: Carbon, color: string}>
     */
    private function datedItems(array $data, BoardColumn $column): Collection
    {
        $status_column = $data['columns']->firstWhere('type', BoardColumn::TYPE_STATUS);

        return $data['groups']->flatMap(function (BoardGroup $group) use ($data, $column, $status_column) {
            return $data['items']->get($group->id, collect())->map(function (BoardItem $item) use ($data, $column, $status_column, $group) {
                $span = $this->spanOf($data['values'][$item->id][$column->id] ?? null);
                if ($span === null) {
                    return null;
                }
                $status_color = $status_column ? $this->optionColor($status_column, $data['values'][$item->id][$status_column->id] ?? null) : null;

                return [...$span, 'color' => $status_color ?? $group->accent_color ?? '#579bfc'];
            });
        })->filter()->values();
    }

    /**
     * Reads a Date (`2026-10-01`, time optional) or Timeline
     * (`2026-09-01..2026-11-21`) value.
     *
     * @return array{start: Carbon, end: Carbon}|null
     */
    private function spanOf(mixed $value): ?array
    {
        if (is_array($value)) {
            $value = $value['date'] ?? $value['from'] ?? null;
        }
        if (! is_string($value) || ! preg_match_all('/\d{4}-\d{2}-\d{2}/', $value, $matches)) {
            return null;
        }

        try {
            $start = Carbon::createFromFormat('Y-m-d', $matches[0][0])->startOfDay();
            $end = isset($matches[0][1]) ? Carbon::createFromFormat('Y-m-d', $matches[0][1])->startOfDay() : $start->copy();
        } catch (Throwable) {
            return null;
        }

        return $end->lessThan($start) ? ['start' => $end, 'end' => $start] : ['start' => $start, 'end' => $end];
    }

    // ── Workload ───────────────────────────────────────────────────────────

    /**
     * How many items each of the busiest people carries, from the first
     * People column.
     *
     * @return array{loads: array<int, int>}
     */
    private function workloadPreview(array $data): array
    {
        $people_column = $data['columns']->firstWhere('type', BoardColumn::TYPE_PEOPLE);
        if ($people_column === null) {
            return ['loads' => []];
        }

        $loads = [];
        foreach ($data['items']->flatten(1) as $item) {
            foreach ((array) ($data['values'][$item->id][$people_column->id] ?? []) as $person_id) {
                if (is_scalar($person_id)) {
                    $loads[(string) $person_id] = ($loads[(string) $person_id] ?? 0) + 1;
                }
            }
        }
        arsort($loads);

        return ['loads' => array_values(array_slice($loads, 0, self::WORKLOAD_PEOPLE_LIMIT))];
    }

    // ── Chart ──────────────────────────────────────────────────────────────

    /**
     * The chart's real numbers, from the same service the Chart tab uses.
     *
     * @return array{chart_type: string, points: array<int, array{label: string, color: string|null, value: float}>, series: array<int, array{color: string|null, values: array<int, float>}>}
     */
    private function chartPreview(WorkspaceNavigationItem $board, ?BoardView $view): array
    {
        $chart_type = (string) ($view?->chart_config['chart_type'] ?? 'bar');

        try {
            $result = $view ? $this->charts->build($board, $view) : null;
        } catch (Throwable) {
            $result = null;
        }
        if (! $result || ! ($result['has_data'] ?? false)) {
            return ['chart_type' => (string) ($result['config']['chart_type'] ?? $chart_type), 'points' => [], 'series' => []];
        }

        $series = collect($result['series']);

        return [
            'chart_type' => (string) $result['config']['chart_type'],
            'points' => collect($result['categories'])
                ->take(self::CHART_POINT_LIMIT)
                ->map(fn (array $category, int $index) => [
                    'label' => (string) ($category['label'] ?? ''),
                    'color' => $category['color'] ?? null,
                    'value' => (float) $series->sum(fn (array $one) => $one['data'][$index] ?? 0),
                ])
                ->values()
                ->all(),
            'series' => $series
                ->take(self::CHART_SERIES_LIMIT)
                ->map(fn (array $one) => [
                    'color' => $one['color'] ?? null,
                    'values' => array_map('floatval', array_slice($one['data'], 0, self::CHART_POINT_LIMIT)),
                ])
                ->values()
                ->all(),
        ];
    }

    // ── Form ───────────────────────────────────────────────────────────────

    /**
     * The form's title, accent color and first visible questions.
     *
     * @return array{title: string, accent_color: string, question_count: int, questions: array<int, array{label: string, type: string}>}
     */
    private function formPreview(WorkspaceNavigationItem $board, ?BoardView $view, ?BoardView $primary_view): array
    {
        $config = $view?->form_config ?? [];
        $source_view = isset($config['source_view_id'])
            ? BoardView::query()->where('board_id', $board->id)->find($config['source_view_id'])
            : $primary_view;

        $columns = $source_view
            ? BoardColumn::query()->where('board_view_id', $source_view->content_view_id)->where('scope', BoardColumn::SCOPE_ITEM)->orderBy('position')->get(['id', 'label', 'type'])->keyBy('id')
            : collect();

        $questions = array_key_exists('questions', $config)
            ? collect($config['questions'])
                ->filter(fn ($question) => ($question['is_visible'] ?? true) && $columns->has((int) ($question['column_id'] ?? 0)))
                ->map(fn ($question) => [
                    'label' => (string) (($question['label'] ?? '') ?: $columns[(int) $question['column_id']]->label),
                    'type' => $columns[(int) $question['column_id']]->type,
                ])
            : $columns->map(fn (BoardColumn $column) => ['label' => $column->label, 'type' => $column->type]);

        return [
            'title' => (string) ($config['title'] ?? $board->label),
            'accent_color' => (string) ($config['accent_color'] ?? '#6161ff'),
            'question_count' => $questions->count(),
            'questions' => $questions->take(self::FORM_QUESTION_LIMIT)->values()->all(),
        ];
    }

    // ── File gallery ───────────────────────────────────────────────────────

    /**
     * The newest files of the gallery, with a real thumbnail for images.
     *
     * @return array{file_count: int, files: array<int, array{extension: string, is_image: bool, url: string|null}>}
     */
    private function filesPreview(?BoardView $view): array
    {
        if ($view === null) {
            return ['file_count' => 0, 'files' => []];
        }

        $query = BoardViewFile::query()->where('board_view_id', $view->id);

        return [
            'file_count' => (clone $query)->count(),
            'files' => $query->latest()->limit(self::FILE_LIMIT)->get()
                ->map(fn (BoardViewFile $file) => [
                    'extension' => strtolower((string) $file->extension),
                    'is_image' => (bool) $file->is_image,
                    'url' => $file->is_image ? $file->download_url : null,
                ])
                ->all(),
        ];
    }

    // ── Manage Workspace ───────────────────────────────────────────────────

    /**
     * What the workspace holds: its cover, color, boards, docs and members.
     *
     * @return array{color: string|null, cover_url: string|null, board_count: int, doc_count: int, member_count: int}
     */
    private function workspacePreview(WorkspaceNavigationItem $board): array
    {
        /** @var Workspace|null $workspace */
        $workspace = $board->workspace;
        if ($workspace === null) {
            return ['color' => null, 'cover_url' => null, 'board_count' => 0, 'doc_count' => 0, 'member_count' => 0];
        }

        $leaf_counts = WorkspaceNavigationItem::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', WorkspaceNavigationItem::TYPE_LEAF)
            ->where('is_archived', false)
            ->where(fn ($query) => $query->whereNull('view_key')->orWhere('view_key', '!=', 'workspace_manage'))
            ->selectRaw("sum(case when view_key = 'doc' then 1 else 0 end) as doc_count, sum(case when view_key = 'doc' then 0 else 1 end) as board_count")
            ->first();

        return [
            'color' => $workspace->color,
            'cover_url' => $workspace->cover_url,
            'board_count' => (int) ($leaf_counts?->board_count ?? 0),
            'doc_count' => (int) ($leaf_counts?->doc_count ?? 0),
            'member_count' => $workspace->users()->count(),
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * The color of the option a Status or Label cell holds.
     */
    private function optionColor(BoardColumn $column, mixed $value): ?string
    {
        $option_id = is_array($value) ? ($value[0] ?? null) : $value;
        if ($option_id === null || $option_id === '') {
            return null;
        }

        $option = collect($column->config['options'] ?? [])->firstWhere('id', $option_id);

        return $option['color'] ?? null;
    }

    /**
     * How long the name bar is drawn, from the name's real length, so rows
     * look like their items instead of identical stripes.
     */
    private function nameRatio(?string $name): float
    {
        return round(min(1, max(0.3, mb_strlen((string) $name) / 36)), 2);
    }
}
