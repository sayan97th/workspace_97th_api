<?php

namespace App\Services\Board;

use App\Enums\BoardViewType;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reading helpers shared by the tabs that show another tab's data instead of
 * holding items of their own: Workload ({@see WorkloadDataService}) and
 * Dashboard ({@see DashboardDataService}). Resolves which tab is read, loads
 * its live root items, and parses the column values those tabs care about.
 */
class BoardViewDataSource
{
    /** Tab kinds with no items of their own, never offered as a data source. */
    public const NON_DATA_VIEW_TYPES = [
        BoardViewType::Chart->value,
        BoardViewType::Doc->value,
        BoardViewType::FileGallery->value,
        BoardViewType::Dashboard->value,
        BoardViewType::Workload->value,
        BoardViewType::Canvas->value,
    ];

    /** Status labels treated as finished work, matching My Work. */
    public const DONE_LABELS = ['done', 'complete', 'completed', 'finished', 'closed'];

    /** monday.com's green, the default color of a "Done" status. */
    public const DONE_COLOR = '#00c875';

    /**
     * The configured tab when it still exists and holds items, otherwise the
     * primary tab, otherwise the first tab that holds items.
     */
    public function resolveSourceView(WorkspaceNavigationItem $board, ?int $configured_id): ?BoardView
    {
        $query = fn () => $board->views()->whereNotIn('view_type', self::NON_DATA_VIEW_TYPES);

        $configured = $configured_id !== null ? $query()->where('id', $configured_id)->first() : null;

        return $configured
            ?? $query()->where('is_primary', true)->first()
            ?? $query()->orderBy('position')->first();
    }

    /**
     * @return array<int, array{id: int, label: string, is_primary: bool}>
     */
    public function sourceViewOptions(WorkspaceNavigationItem $board): array
    {
        return $board->views()
            ->whereNotIn('view_type', self::NON_DATA_VIEW_TYPES)
            ->orderBy('position')
            ->get(['id', 'label', 'is_primary'])
            ->map(fn (BoardView $view) => ['id' => $view->id, 'label' => $view->label, 'is_primary' => (bool) $view->is_primary])
            ->values()
            ->all();
    }

    /**
     * Item scoped columns of the tab, optionally narrowed to some kinds.
     *
     * @param  array<int, string>|null  $types
     * @return Collection<int, BoardColumn>
     */
    public function columns(BoardView $view, ?array $types = null): Collection
    {
        return $view->columns()
            ->where('scope', BoardColumn::SCOPE_ITEM)
            ->when($types !== null, fn ($query) => $query->whereIn('type', $types))
            ->orderBy('position')
            ->get();
    }

    /**
     * @param  Collection<int, BoardColumn>  $columns
     * @return array<int, array{id: string, label: string, type: string}>
     */
    public function columnOptions(Collection $columns): array
    {
        return $columns
            ->map(fn (BoardColumn $column) => ['id' => (string) $column->id, 'label' => $column->label, 'type' => $column->type])
            ->values()
            ->all();
    }

    /**
     * The tab's live root items (not archived, not in an archived group) with
     * their values and group.
     *
     * @return Collection<int, BoardItem>
     */
    public function rootItems(BoardView $view): Collection
    {
        $group_ids = BoardGroup::where('board_view_id', $view->id)->where('is_archived', false)->pluck('id');

        return BoardItem::query()
            ->whereIn('group_id', $group_ids)
            ->whereNull('parent_id')
            ->where('is_archived', false)
            ->with(['values', 'group:id,name,accent_color,position'])
            ->orderBy('position')
            ->get();
    }

    public function rawValue(BoardItem $item, ?BoardColumn $column): mixed
    {
        if ($column === null) {
            return null;
        }

        return $item->values->firstWhere('column_id', $column->id)?->value;
    }

    /**
     * User ids assigned in a People value (team entries are skipped).
     *
     * @return array<int, int>
     */
    public function peopleIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($value, fn ($id) => is_numeric($id)))));
    }

    /**
     * A Date value as a one day range, or a Timeline value (either the
     * `{start, end}` object or the older `start..end` string) as its range.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function dateRange(mixed $value): ?array
    {
        $start = null;
        $end = null;

        if (is_array($value)) {
            $start = $value['start'] ?? null;
            $end = $value['end'] ?? null;
        } elseif (is_string($value) && str_contains($value, '..')) {
            [$start, $end] = explode('..', $value, 2);
        } elseif (is_string($value) && $value !== '') {
            $start = $value;
            $end = $value;
        }

        $start = $start ?: $end;
        $end = $end ?: $start;
        if (! $start || ! $end) {
            return null;
        }

        try {
            $start_date = Carbon::parse(substr((string) $start, 0, 10))->startOfDay();
            $end_date = Carbon::parse(substr((string) $end, 0, 10))->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $start_date->lte($end_date) ? [$start_date, $end_date] : [$end_date, $start_date];
    }

    public function numberValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric(trim($value)) ? (float) trim($value) : null;
    }

    /**
     * @return array{id: string, label: string, color: string}|null
     */
    public function option(BoardColumn $column, mixed $value): ?array
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        $option = collect($column->config['options'] ?? [])
            ->first(fn ($option) => is_array($option) && (string) ($option['id'] ?? '') === (string) $value);

        return $option ? [
            'id' => (string) $option['id'],
            'label' => (string) ($option['label'] ?? ''),
            'color' => (string) ($option['color'] ?? '#c4c4c4'),
        ] : null;
    }

    /**
     * @param  array{label: string, color: string}  $option
     */
    public function isDoneOption(array $option): bool
    {
        return in_array(strtolower(trim($option['label'])), self::DONE_LABELS, true)
            || strtolower($option['color']) === self::DONE_COLOR;
    }

    /**
     * @param  array<int, int>  $user_ids
     * @return Collection<int, array{id: int, name: string, photo_url: string|null, is_deactivated: bool}>
     */
    public function people(array $user_ids): Collection
    {
        if ($user_ids === []) {
            return collect();
        }

        return User::withTrashed()
            ->whereIn('id', $user_ids)
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => [
                'id' => $user->id,
                'name' => $user->full_name,
                'photo_url' => $user->profile_photo_url,
                'is_deactivated' => (bool) $user->is_deactivated,
            ]]);
    }
}
