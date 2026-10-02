<?php

namespace App\Services\Board;

use App\Http\Resources\BoardItemResource;
use App\Models\BoardAutomationSetting;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemDependencyLink;
use App\Models\BoardItemValue;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Schedules items from the items they depend on, the way monday.com's Dependency column does.
 *
 * A Dependency column lists each item's predecessors and names the Date or Timeline column it
 * schedules (`config.date_column_id`). Each link has a type and a lag in days
 * ({@see BoardItemDependencyLink}), so "Write show notes" can be "7 days after Record Episode" and
 * "Promo email" can be "3 days before Publish". When a predecessor's date changes, its dependent
 * items are moved, and theirs after them:
 *
 * - Strict: a dependent item always sits exactly at its lag, earlier or later.
 * - Flexible: a dependent item is only pushed later, when the change would break its lag.
 * - No action: nothing moves.
 *
 * In Strict mode, changing a dependent item's own date by hand keeps it there and updates the
 * lag of its links instead, so the next change of a predecessor keeps the new distance.
 *
 * Every write goes through {@see BoardItemValueService::sync()}, so moved dates get the same
 * activity entries and automations as an edit made by hand. The ids of the items it moved are
 * kept for the request ({@see pullMovedItemIds()}) so the response can send them back.
 */
class BoardDependencyScheduler
{
    /** Stops a very long chain, and any cycle saved before cycles were refused. */
    private const MAX_CASCADE_DEPTH = 40;

    /** The most dates one change may move. */
    private const MAX_MOVED_ITEMS = 500;

    /** Nested writes this scheduler is making right now. Above zero, a date change is a move, not an edit by hand. */
    private int $cascade_depth = 0;

    /** @var array<int, true> Items whose dependents are being scheduled right now, a cycle guard. */
    private array $in_progress = [];

    /** @var array<int, true> */
    private array $moved_item_ids = [];

    /** @var array<int, WorkingCalendar> */
    private array $calendars = [];

    /**
     * Called by {@see BoardItemValueService::sync()} after every cell write.
     */
    public function handleValueChanged(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if ($column->type === BoardColumn::TYPE_DEPENDENCY) {
            $dependency_column = $this->tableColumn($item, $column->id);
            if ($dependency_column) {
                $this->syncLinks($item, $dependency_column, $new_value);
                $this->reschedule($item, $dependency_column, $actor);
            }

            return;
        }

        if (! in_array($column->type, [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE], true) || $this->sameValue($old_value, $new_value)) {
            return;
        }

        foreach ($this->dependencyColumnsScheduling($item, $column->id) as $dependency_column) {
            if ($this->cascade_depth === 0 && $dependency_column->dependencyMode() === BoardColumn::DEPENDENCY_MODE_STRICT) {
                $this->recaptureLags($item, $dependency_column, $column);
            }
            $this->rescheduleDependents($item, $dependency_column, $old_value, $actor);
        }
    }

    /**
     * Replaces an item's links on a Dependency column: which items it depends on, and for each
     * one its type and lag. A link sent without a lag keeps the lag it had, or, when it is new,
     * starts from the distance the two items already have, so adding a link never moves anything
     * by surprise.
     *
     * @param  array<int, array{predecessor_id: int|string, type?: string|null, lag_days?: int|null}>  $links
     */
    public function setLinks(WorkspaceNavigationItem $board, BoardItem $item, BoardColumn $column, array $links, ?User $actor): void
    {
        DB::transaction(function () use ($board, $item, $column, $links, $actor) {
            $ids = [];
            foreach ($links as $link) {
                $predecessor_id = (int) $link['predecessor_id'];
                $ids[] = (string) $predecessor_id;

                $existing = BoardItemDependencyLink::where('column_id', $column->id)->where('item_id', $item->id)->where('predecessor_id', $predecessor_id)->first();
                $type = in_array($link['type'] ?? null, BoardItemDependencyLink::TYPES, true) ? $link['type'] : ($existing?->type ?? BoardItemDependencyLink::TYPE_FINISH_TO_START);

                if ($existing) {
                    $existing->type = $type;
                    if (isset($link['lag_days'])) {
                        $existing->lag_days = $this->clampLag((int) $link['lag_days']);
                    }
                    $existing->save();
                } elseif (isset($link['lag_days'])) {
                    BoardItemDependencyLink::create(['column_id' => $column->id, 'item_id' => $item->id, 'predecessor_id' => $predecessor_id, 'type' => $type, 'lag_days' => $this->clampLag((int) $link['lag_days'])]);
                } else {
                    $this->ensureLink($item, $column, $predecessor_id, $type);
                }
            }

            // Writing the cell runs `handleValueChanged()`, which drops removed links and schedules the item.
            app(BoardItemValueService::class)->sync($board, $item, [(string) $column->id => $ids === [] ? null : $ids], $actor);
        });
    }

    /**
     * Ids of the items this request moved, cleared once read.
     *
     * @return array<int, int>
     */
    public function pullMovedItemIds(): array
    {
        $ids = array_keys($this->moved_item_ids);
        $this->moved_item_ids = [];

        return $ids;
    }

    /**
     * The items this request moved, other than `$except_id` (the item the request itself
     * edited, sent back on its own), ready for {@see BoardItemResource}.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BoardItem>
     */
    public function movedItems(int $except_id): \Illuminate\Database\Eloquent\Collection
    {
        $ids = array_values(array_diff($this->pullMovedItemIds(), [$except_id]));

        return BoardItem::whereIn('id', $ids)->with(['values', 'dependencyLinks'])->get();
    }

    /**
     * Whether linking `$item` to depend on `$predecessor_id` would close a loop, because the
     * predecessor already depends on `$item`, directly or through other items.
     */
    public function wouldCreateCycle(BoardColumn $column, BoardItem $item, int $predecessor_id): bool
    {
        if ($predecessor_id === $item->id) {
            return true;
        }

        $predecessors_of = BoardItemValue::where('column_id', $column->id)->get(['item_id', 'value'])
            ->mapWithKeys(fn (BoardItemValue $value) => [$value->item_id => $this->predecessorIds($value->value)])
            ->all();

        $stack = [$predecessor_id];
        $seen = [];
        while ($stack !== []) {
            $current = array_pop($stack);
            if ($current === $item->id) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            array_push($stack, ...($predecessors_of[$current] ?? []));
        }

        return false;
    }

    /**
     * Moves the date of every item that depends on `$predecessor`.
     */
    private function rescheduleDependents(BoardItem $predecessor, BoardColumn $dependency_column, mixed $predecessor_old_value, ?User $actor): void
    {
        if (isset($this->in_progress[$predecessor->id]) || $this->cascade_depth >= self::MAX_CASCADE_DEPTH) {
            return;
        }

        $this->in_progress[$predecessor->id] = true;
        try {
            foreach ($this->dependentsOf($predecessor, $dependency_column) as $dependent) {
                // A link saved before lags existed starts from the distance the two items had before this change.
                $this->ensureLink($dependent, $dependency_column, $predecessor->id, BoardItemDependencyLink::TYPE_FINISH_TO_START, $predecessor_old_value);
                $this->reschedule($dependent, $dependency_column, $actor);
            }
        } finally {
            unset($this->in_progress[$predecessor->id]);
        }
    }

    /**
     * Moves `$item`'s date to where its links put it, following the column's mode.
     */
    private function reschedule(BoardItem $item, BoardColumn $dependency_column, ?User $actor): void
    {
        $mode = $dependency_column->dependencyMode();
        $date_column = $this->dateColumnOf($item, $dependency_column);
        if ($mode === BoardColumn::DEPENDENCY_MODE_NONE || ! $date_column || count($this->moved_item_ids) >= self::MAX_MOVED_ITEMS) {
            return;
        }

        $predecessor_ids = $this->predecessorIds($this->currentValue($item, $dependency_column->id));
        if ($predecessor_ids === []) {
            return;
        }

        $links = BoardItemDependencyLink::where('column_id', $dependency_column->id)->where('item_id', $item->id)->get()->keyBy('predecessor_id');
        $predecessor_values = BoardItemValue::where('column_id', $date_column->id)
            ->whereIn('item_id', $predecessor_ids)
            ->whereHas('item', fn ($query) => $query->where('is_archived', false))
            ->get()
            ->keyBy('item_id');

        $calendar = $this->calendarFor($item, $dependency_column);
        $current = $this->currentValue($item, $date_column->id);
        $target = $date_column->type === BoardColumn::TYPE_TIMELINE
            ? $this->timelineTarget($current, $predecessor_ids, $predecessor_values, $links, $calendar)
            : $this->dateTarget($current, $predecessor_ids, $predecessor_values, $links, $calendar);
        if ($target === null || $this->sameValue($current, $target)) {
            return;
        }

        if ($mode === BoardColumn::DEPENDENCY_MODE_FLEXIBLE && ! $this->startsBefore($current, $target)) {
            return;
        }

        $this->moved_item_ids[$item->id] = true;
        $this->cascade_depth++;
        try {
            app(BoardItemValueService::class)->sync($item->board, $item, [(string) $date_column->id => $target], $actor);
        } finally {
            $this->cascade_depth--;
        }
    }

    /**
     * A Date column's target: the latest of each predecessor's date plus its lag. The time of day
     * the item already had is kept.
     *
     * @param  array<int, int>  $predecessor_ids
     * @param  Collection<int, BoardItemValue>  $predecessor_values
     * @param  Collection<int, BoardItemDependencyLink>  $links
     */
    private function dateTarget(mixed $current, array $predecessor_ids, Collection $predecessor_values, Collection $links, ?WorkingCalendar $calendar): ?string
    {
        $latest = null;
        foreach ($predecessor_ids as $predecessor_id) {
            $anchor = $this->rangeOf($predecessor_values->get($predecessor_id)?->value);
            if ($anchor === null) {
                continue;
            }
            // On a single date there is no start or end to tell apart, so every type means "the date plus the lag".
            $candidate = $this->addDays($anchor['end'], (int) ($links->get($predecessor_id)?->lag_days ?? 0), $calendar);
            $latest = $latest === null || $candidate->greaterThan($latest) ? $candidate : $latest;
        }

        if ($latest === null) {
            return null;
        }

        $time = is_string($current) && strlen($current) > 10 ? substr($current, 10) : '';

        return $latest->toDateString().$time;
    }

    /**
     * A Timeline column's target: the latest start any link allows, keeping the item's length.
     *
     * @param  array<int, int>  $predecessor_ids
     * @param  Collection<int, BoardItemValue>  $predecessor_values
     * @param  Collection<int, BoardItemDependencyLink>  $links
     * @return array{start: string, end: string}|null
     */
    private function timelineTarget(mixed $current, array $predecessor_ids, Collection $predecessor_values, Collection $links, ?WorkingCalendar $calendar): ?array
    {
        $own = $this->rangeOf($current);
        $length = $own === null ? 0 : $this->daysBetween($own['start'], $own['end'], $calendar);

        $latest = null;
        foreach ($predecessor_ids as $predecessor_id) {
            $anchor = $this->rangeOf($predecessor_values->get($predecessor_id)?->value);
            if ($anchor === null) {
                continue;
            }

            $link = $links->get($predecessor_id);
            $lag = (int) ($link?->lag_days ?? 0);
            $candidate = match ($link?->type ?? BoardItemDependencyLink::TYPE_FINISH_TO_START) {
                BoardItemDependencyLink::TYPE_START_TO_START => $this->addDays($anchor['start'], $lag, $calendar),
                BoardItemDependencyLink::TYPE_FINISH_TO_FINISH => $this->addDays($this->addDays($anchor['end'], $lag, $calendar), -$length, $calendar),
                BoardItemDependencyLink::TYPE_START_TO_FINISH => $this->addDays($this->addDays($anchor['start'], $lag, $calendar), -$length, $calendar),
                default => $this->addDays($anchor['end'], 1 + $lag, $calendar),
            };
            $latest = $latest === null || $candidate->greaterThan($latest) ? $candidate : $latest;
        }

        if ($latest === null) {
            return null;
        }

        return ['start' => $latest->toDateString(), 'end' => $this->addDays($latest, $length, $calendar)->toDateString()];
    }

    /**
     * The lag that puts `$item` exactly where it is now, from a predecessor's date.
     */
    private function capturedLag(string $type, mixed $predecessor_value, mixed $item_value, bool $is_timeline, ?WorkingCalendar $calendar): ?int
    {
        $anchor = $this->rangeOf($predecessor_value);
        $own = $this->rangeOf($item_value);
        if ($anchor === null || $own === null) {
            return null;
        }

        if (! $is_timeline) {
            return $this->clampLag($this->daysBetween($anchor['end'], $own['start'], $calendar));
        }

        return $this->clampLag(match ($type) {
            BoardItemDependencyLink::TYPE_START_TO_START => $this->daysBetween($anchor['start'], $own['start'], $calendar),
            BoardItemDependencyLink::TYPE_FINISH_TO_FINISH => $this->daysBetween($anchor['end'], $own['end'], $calendar),
            BoardItemDependencyLink::TYPE_START_TO_FINISH => $this->daysBetween($anchor['start'], $own['end'], $calendar),
            default => $this->daysBetween($anchor['end'], $own['start'], $calendar) - 1,
        });
    }

    /**
     * After a date was changed by hand in Strict mode: every link of the item gets the lag that
     * keeps it on its new date.
     */
    private function recaptureLags(BoardItem $item, BoardColumn $dependency_column, BoardColumn $date_column): void
    {
        $predecessor_ids = $this->predecessorIds($this->currentValue($item, $dependency_column->id));
        $item_value = $this->currentValue($item, $date_column->id);
        if ($predecessor_ids === [] || $this->rangeOf($item_value) === null) {
            return;
        }

        $calendar = $this->calendarFor($item, $dependency_column);
        foreach ($predecessor_ids as $predecessor_id) {
            $link = $this->ensureLink($item, $dependency_column, $predecessor_id, BoardItemDependencyLink::TYPE_FINISH_TO_START);
            $lag = $this->capturedLag($link->type, $this->currentValue($predecessor_id, $date_column->id), $item_value, $date_column->type === BoardColumn::TYPE_TIMELINE, $calendar);
            if ($lag !== null && $lag !== $link->lag_days) {
                $link->update(['lag_days' => $lag]);
            }
        }
    }

    /**
     * Drops the links of predecessors no longer in the cell and adds the missing ones.
     */
    private function syncLinks(BoardItem $item, BoardColumn $dependency_column, mixed $value): void
    {
        $predecessor_ids = $this->predecessorIds($value);

        BoardItemDependencyLink::where('column_id', $dependency_column->id)
            ->where('item_id', $item->id)
            ->when($predecessor_ids !== [], fn ($query) => $query->whereNotIn('predecessor_id', $predecessor_ids))
            ->delete();

        foreach ($predecessor_ids as $predecessor_id) {
            $this->ensureLink($item, $dependency_column, $predecessor_id, BoardItemDependencyLink::TYPE_FINISH_TO_START);
        }
    }

    /**
     * The link row, created when missing with the lag the two items already have (in Strict mode)
     * so that creating it moves nothing. `$predecessor_value` overrides the predecessor's current
     * date, used right after it changed.
     */
    private function ensureLink(BoardItem $item, BoardColumn $dependency_column, int $predecessor_id, string $type, mixed $predecessor_value = null): BoardItemDependencyLink
    {
        $existing = BoardItemDependencyLink::where('column_id', $dependency_column->id)->where('item_id', $item->id)->where('predecessor_id', $predecessor_id)->first();
        if ($existing) {
            return $existing;
        }

        $lag = 0;
        $date_column = $this->dateColumnOf($item, $dependency_column);
        if ($date_column && $dependency_column->dependencyMode() === BoardColumn::DEPENDENCY_MODE_STRICT) {
            $lag = $this->capturedLag(
                $type,
                $predecessor_value ?? $this->currentValue($predecessor_id, $date_column->id),
                $this->currentValue($item, $date_column->id),
                $date_column->type === BoardColumn::TYPE_TIMELINE,
                $this->calendarFor($item, $dependency_column),
            ) ?? 0;
        }

        return BoardItemDependencyLink::firstOrCreate(
            ['column_id' => $dependency_column->id, 'item_id' => $item->id, 'predecessor_id' => $predecessor_id],
            ['type' => $type, 'lag_days' => $lag],
        );
    }

    /**
     * Dependency columns of `$item`'s table that schedule `$date_column_id` and may move dates.
     *
     * @return Collection<int, BoardColumn>
     */
    private function dependencyColumnsScheduling(BoardItem $item, int $date_column_id): Collection
    {
        return BoardColumn::where('board_view_id', $item->group->board_view_id)
            ->where('scope', $this->scopeOf($item))
            ->where('type', BoardColumn::TYPE_DEPENDENCY)
            ->get()
            ->filter(fn (BoardColumn $column) => $column->dependencyDateColumnId() === $date_column_id && $column->dependencyMode() !== BoardColumn::DEPENDENCY_MODE_NONE)
            ->values();
    }

    /**
     * Items (not archived) whose cell on `$dependency_column` lists `$predecessor`.
     *
     * @return Collection<int, BoardItem>
     */
    private function dependentsOf(BoardItem $predecessor, BoardColumn $dependency_column): Collection
    {
        return BoardItemValue::where('column_id', $dependency_column->id)
            ->whereHas('item', fn ($query) => $query->where('is_archived', false))
            ->with('item.group')
            ->get()
            ->filter(fn (BoardItemValue $value) => in_array($predecessor->id, $this->predecessorIds($value->value), true))
            ->map(fn (BoardItemValue $value) => $value->item)
            ->values();
    }

    /**
     * The full column, when it belongs to `$item`'s own table and scope.
     */
    private function tableColumn(BoardItem $item, ?int $column_id): ?BoardColumn
    {
        if ($column_id === null) {
            return null;
        }

        return BoardColumn::where('board_view_id', $item->group->board_view_id)->where('scope', $this->scopeOf($item))->find($column_id);
    }

    private function dateColumnOf(BoardItem $item, BoardColumn $dependency_column): ?BoardColumn
    {
        $column = $this->tableColumn($item, $dependency_column->dependencyDateColumnId());

        return $column && in_array($column->type, [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE], true) ? $column : null;
    }

    private function calendarFor(BoardItem $item, BoardColumn $dependency_column): ?WorkingCalendar
    {
        if (empty($dependency_column->config['use_working_days'])) {
            return null;
        }

        return $this->calendars[$item->board_id] ??= BoardAutomationSetting::forBoard($item->board_id)->calendar();
    }

    private function scopeOf(BoardItem $item): string
    {
        return $item->parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;
    }

    private function currentValue(BoardItem|int $item, int $column_id): mixed
    {
        $item_id = $item instanceof BoardItem ? $item->id : $item;

        return BoardItemValue::where('item_id', $item_id)->where('column_id', $column_id)->first()?->value;
    }

    /**
     * @return array<int, int>
     */
    private function predecessorIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($value, fn ($id) => is_numeric($id)))));
    }

    /**
     * A Date value as a one day range, or a Timeline value's range, null when there is no valid date.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function rangeOf(mixed $value): ?array
    {
        if (is_string($value)) {
            $date = $this->parseDate($value);

            return $date ? ['start' => $date, 'end' => $date] : null;
        }

        if (! is_array($value)) {
            return null;
        }

        $start = $this->parseDate($value['start'] ?? null);
        $end = $this->parseDate($value['end'] ?? null);
        if ($start === null && $end === null) {
            return null;
        }

        return ['start' => $start ?? $end, 'end' => $end ?? $start];
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr($value, 0, 10))->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function addDays(CarbonImmutable $date, int $days, ?WorkingCalendar $calendar): CarbonImmutable
    {
        return $calendar ? $calendar->addWorkingDays($date, $days) : $date->addDays($days);
    }

    private function daysBetween(CarbonImmutable $from, CarbonImmutable $to, ?WorkingCalendar $calendar): int
    {
        return $calendar ? $calendar->workingDaysBetween($from, $to) : (int) $from->diffInDays($to, false);
    }

    /**
     * Flexible mode only moves an item later: true when it has no date yet, or its target starts after it.
     *
     * @param  string|array{start: string, end: string}  $target
     */
    private function startsBefore(mixed $current, string|array $target): bool
    {
        $own = $this->rangeOf($current);
        $wanted = $this->rangeOf($target);

        return $own === null || ($wanted !== null && $own['start']->lessThan($wanted['start']));
    }

    private function clampLag(int $lag): int
    {
        return max(-BoardItemDependencyLink::MAX_LAG_DAYS, min(BoardItemDependencyLink::MAX_LAG_DAYS, $lag));
    }

    private function sameValue(mixed $a, mixed $b): bool
    {
        return json_encode($a) === json_encode($b);
    }
}
