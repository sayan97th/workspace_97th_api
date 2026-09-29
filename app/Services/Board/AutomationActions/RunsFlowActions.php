<?php

namespace App\Services\Board\AutomationActions;

use App\Models\BoardAutomation;
use App\Models\BoardAutomationSetting;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\User;
use App\Services\Board\BoardAutomationActionOutcome;
use App\Services\Board\BoardAutomationActionRunner;
use App\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The actions of {@see BoardAutomationActionRunner} that reach past the item itself: assign people
 * in turn, write every subitem or the parent, add checklist tasks, and move the items that depend
 * on this one.
 */
trait RunsFlowActions
{
    /** Most dependent items one "shift dependents" action moves. */
    private const MAX_DEPENDENTS = 50;

    /** Most subitems one "set every subitem" action writes. */
    private const MAX_CASCADE_SUBITEMS = 200;

    /**
     * Assigns the next person of `user_ids`: in turn (`rotation`, remembered in the automation's
     * `state`) or whoever holds the fewest open items in that column (`least_busy`).
     *
     * @param  array<string, mixed>  $params
     */
    private function assignRoundRobin(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor, string $action_key): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, BoardColumn::TYPE_PEOPLE);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $pool_ids = array_values(array_unique(array_map('intval', (array) ($params['user_ids'] ?? []))));
        $pool = User::whereIn('id', $pool_ids)->where('is_active', true)->get()->keyBy('id');
        $pool_ids = array_values(array_filter($pool_ids, fn (int $id) => $pool->has($id)));
        if ($pool_ids === []) {
            return BoardAutomationActionOutcome::skipped('Nobody in the rotation is still active.');
        }

        $state_key = "round_robin:{$action_key}";
        if (($params['strategy'] ?? 'rotation') === 'least_busy') {
            $loads = $this->openItemCounts($automation, $column, $pool_ids);
            $next_id = collect($pool_ids)->sortBy(fn (int $id) => [$loads[$id] ?? 0, array_search($id, $pool_ids, true)])->first();
        } else {
            $last_id = (int) (($automation->state ?? [])[$state_key] ?? 0);
            $last_index = array_search($last_id, $pool_ids, true);
            $next_id = $pool_ids[$last_index === false ? 0 : ($last_index + 1) % count($pool_ids)];
        }
        $person = $pool->get($next_id);

        $current_ids = $this->peopleIds($this->currentValue($subject, $column));
        $next_ids = ! empty($params['replace']) ? [(string) $person->id] : array_values(array_unique([...$current_ids, (string) $person->id]));
        if ($this->valuesAreEqual($current_ids, $next_ids)) {
            return BoardAutomationActionOutcome::skipped("{$person->full_name} was already assigned.");
        }

        $this->writeValue($subject, $column, $next_ids, $actor);
        if ($automation->exists && ! $this->run_context->isDryRun()) {
            $automation->forceFill(['state' => [...($automation->state ?? []), $state_key => $person->id]])->saveQuietly();
        }
        $this->log($automation, $subject, $actor, "assigned {$person->full_name} to \"{$subject->name}\" in turn");

        return BoardAutomationActionOutcome::success("Assigned {$person->full_name} in \"{$column->label}\".");
    }

    /**
     * How many open (not archived) items of the tab each person holds in `$column`.
     *
     * @param  array<int, int>  $user_ids
     * @return array<int, int>
     */
    private function openItemCounts(BoardAutomation $automation, BoardColumn $column, array $user_ids): array
    {
        $counts = array_fill_keys($user_ids, 0);
        BoardItemValue::where('column_id', $column->id)
            ->whereHas('item', fn ($query) => $query->where('is_archived', false))
            ->pluck('value')
            ->each(function ($value) use (&$counts) {
                foreach ($this->peopleIds($value) as $id) {
                    if (isset($counts[(int) $id])) {
                        $counts[(int) $id]++;
                    }
                }
            });

        return $counts;
    }

    /**
     * Writes `value` into the subitem column `target_column_id` of every subitem of the item.
     *
     * @param  array<string, mixed>  $params
     */
    private function setSubitemsValue(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $column = BoardColumn::where('board_view_id', $automation->board_view_id)->where('scope', BoardColumn::SCOPE_SUBITEM)->find((int) ($params['target_column_id'] ?? 0));
        if (! $column) {
            return BoardAutomationActionOutcome::skipped('The subitem column to update no longer exists.');
        }
        if ($item->parent_id !== null) {
            return BoardAutomationActionOutcome::skipped('This item is itself a subitem.');
        }

        $value = $params['value'] ?? null;
        $subitems = BoardItem::where('parent_id', $item->id)->where('is_archived', false)->with('group')->limit(self::MAX_CASCADE_SUBITEMS)->get();
        $changed = 0;
        foreach ($subitems as $subitem) {
            if ($this->valuesAreEqual($this->currentValue($subitem, $column), $value)) {
                continue;
            }
            $this->writeValue($subitem, $column, $value, $actor);
            $changed++;
        }

        if ($changed === 0) {
            return BoardAutomationActionOutcome::skipped($subitems->isEmpty() ? 'The item has no subitems.' : "Every subitem already had that \"{$column->label}\".");
        }

        $shown = $this->renderer->displayValue($column, $value);
        $this->log($automation, $item, $actor, "set \"{$column->label}\" to \"{$shown}\" on {$changed} subitem(s) of \"{$item->name}\"");

        return BoardAutomationActionOutcome::success("Set \"{$column->label}\" to \"{$shown}\" on {$changed} subitem(s).");
    }

    /**
     * Writes `value` into the item column `target_column_id` of a subitem's parent.
     *
     * @param  array<string, mixed>  $params
     */
    private function setParentValue(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        if ($item->parent_id === null) {
            return BoardAutomationActionOutcome::skipped('Only a subitem has a parent item to update.');
        }

        return $this->setColumnValue($automation, $params, $item, $actor);
    }

    /**
     * Adds a task to the checklist column for every entry of `tasks` it does not have yet.
     *
     * @param  array<string, mixed>  $params
     */
    private function addChecklistItems(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, BoardColumn::TYPE_CHECKLIST);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $current = array_values(array_filter((array) $this->currentValue($subject, $column), 'is_array'));
        $existing = array_map(fn (array $task) => mb_strtolower(trim((string) ($task['text'] ?? ''))), $current);
        $added = [];
        foreach ((array) ($params['tasks'] ?? []) as $text) {
            $text = trim((string) $text);
            if ($text === '' || in_array(mb_strtolower($text), $existing, true)) {
                continue;
            }
            $existing[] = mb_strtolower($text);
            $added[] = ['id' => 'task_'.Str::lower(Str::random(10)), 'text' => mb_substr($text, 0, 250), 'is_done' => false];
        }

        if ($added === []) {
            return BoardAutomationActionOutcome::skipped("\"{$column->label}\" already had those tasks.");
        }

        $this->writeValue($subject, $column, [...$current, ...$added], $actor);
        $count = count($added);
        $this->log($automation, $subject, $actor, "added {$count} task(s) to \"{$column->label}\" on \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success("Added {$count} task(s) to \"{$column->label}\".");
    }

    /**
     * Moves the date or timeline of every item that depends on this one (its dependency column
     * points at this item). `strict` keeps the gap: they move as far as this item's date just
     * moved. `flexible`, and `strict` when the move is not known, only pushes an item that would
     * now start before this one ends, to the day after.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function shiftDependents(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE]);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $dependency_column = BoardColumn::where('board_view_id', $automation->board_view_id)->where('type', BoardColumn::TYPE_DEPENDENCY)->find((int) ($params['dependency_column_id'] ?? 0));
        if (! $dependency_column) {
            return BoardAutomationActionOutcome::skipped('The dependency column no longer exists.');
        }

        $own_end = $this->dateEnd($this->currentValue($subject, $column));
        if ($own_end === null) {
            return BoardAutomationActionOutcome::skipped("\"{$column->label}\" has no date on this item.");
        }

        $calendar = ! empty($params['use_working_days']) ? BoardAutomationSetting::forBoard($automation->board_id)->calendar() : null;
        $delta = ($params['mode'] ?? 'strict') === 'strict' ? $this->dateDelta($column, $context, $calendar) : null;

        $dependents = BoardItemValue::where('column_id', $dependency_column->id)
            ->whereHas('item', fn ($query) => $query->where('is_archived', false))
            ->with('item.group')
            ->get()
            ->filter(fn (BoardItemValue $value) => in_array((string) $subject->id, array_map('strval', array_filter((array) $value->value, 'is_scalar')), true))
            ->take(self::MAX_DEPENDENTS);

        $moved = 0;
        foreach ($dependents as $value) {
            $dependent = $value->item;
            $current = $this->currentValue($dependent, $column);
            $start = $this->dateString($current) ?? $this->timelineRange($current)['start'] ?? null;
            if ($start === null) {
                continue;
            }

            if ($delta !== null) {
                $days = $delta;
            } else {
                $earliest = CarbonImmutable::parse(substr($own_end, 0, 10))->addDay();
                $earliest = $calendar ? $calendar->nextWorkingDay($earliest) : $earliest;
                $start_date = CarbonImmutable::parse(substr($start, 0, 10));
                if ($start_date->greaterThanOrEqualTo($earliest)) {
                    continue;
                }
                $days = $calendar ? $calendar->workingDaysBetween($start_date, $earliest) : (int) $start_date->diffInDays($earliest);
            }
            if ($days === 0) {
                continue;
            }

            $shift = fn (string $date) => $this->moveByDays($date, $days, $calendar);
            $range = $this->timelineRange($current);
            $next = $column->type === BoardColumn::TYPE_TIMELINE && $range !== null
                ? ['start' => $shift($range['start']), 'end' => $shift($range['end'])]
                : $shift((string) $this->dateString($current));

            $this->writeValue($dependent, $column, $next, $actor);
            $moved++;
        }

        if ($moved === 0) {
            return BoardAutomationActionOutcome::skipped('No dependent item needed to move.');
        }

        $this->log($automation, $subject, $actor, "moved \"{$column->label}\" of {$moved} item(s) that depend on \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success("Moved \"{$column->label}\" of {$moved} dependent item(s).");
    }

    /**
     * How far the trigger's date just moved, in days (or working days), when the trigger watched
     * this same column. The end of a timeline counts.
     *
     * @param  array<string, mixed>  $context
     */
    private function dateDelta(BoardColumn $column, array $context, ?WorkingCalendar $calendar): ?int
    {
        $trigger_column = $context['column'] ?? null;
        if (! $trigger_column instanceof BoardColumn || $trigger_column->id !== $column->id) {
            return null;
        }

        $old_end = $this->dateEnd($context['old_value'] ?? null);
        $new_end = $this->dateEnd($context['new_value'] ?? null);
        if ($old_end === null || $new_end === null) {
            return null;
        }

        $from = CarbonImmutable::parse(substr($old_end, 0, 10));
        $to = CarbonImmutable::parse(substr($new_end, 0, 10));

        return $calendar ? $calendar->workingDaysBetween($from, $to) : (int) $from->diffInDays($to, false);
    }

    /**
     * Moves `YYYY-MM-DD` (keeping any time) by days, or by working days of `$calendar`.
     */
    private function moveByDays(string $value, int $days, ?WorkingCalendar $calendar): string
    {
        if ($calendar === null) {
            return $this->moveDateString($value, $days, 'days');
        }

        return $calendar->addWorkingDays(CarbonImmutable::parse(substr($value, 0, 10)), $days)->toDateString().substr($value, 10);
    }
}
