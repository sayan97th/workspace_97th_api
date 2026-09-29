<?php

namespace App\Services\Board\AutomationActions;

use App\Models\BoardAutomation;
use App\Models\BoardAutomationSetting;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\User;
use App\Services\Board\BoardAutomationActionOutcome;
use App\Services\Board\BoardAutomationActionRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The date actions of {@see BoardAutomationActionRunner}: push a date, set a date from another one,
 * keep one date after another (a simple dependency) and set a timeline. "Push date" (in days) and
 * "set timeline" can count working days of the board's calendar with `use_working_days`.
 *
 * A date column stores `YYYY-MM-DD` or `YYYY-MM-DDTHH:mm`, a timeline column `{start, end}`. A time
 * of day is kept whenever a date is moved.
 */
trait RunsDateActions
{
    /** Longest a pushed timeline may last or a date may move, in days, so a typo never lands in year 9999. */
    private const MAX_DATE_SHIFT_DAYS = 3650;

    /**
     * @param  array<string, mixed>  $params
     */
    private function shiftDate(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE]);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $amount = (int) ($params['amount'] ?? 0);
        $unit = in_array($params['unit'] ?? 'days', ['days', 'weeks', 'months'], true) ? $params['unit'] ?? 'days' : 'days';
        if ($amount === 0) {
            return BoardAutomationActionOutcome::skipped('The amount to push the date by is zero.');
        }

        $current = $this->currentValue($subject, $column);
        $calendar = ! empty($params['use_working_days']) && $unit === 'days' ? BoardAutomationSetting::forBoard($automation->board_id)->calendar() : null;
        $shift = fn (?string $value) => $value === null ? null : ($calendar ? $this->moveByDays($value, $amount, $calendar) : $this->moveDateString($value, $amount, $unit));

        if ($column->type === BoardColumn::TYPE_TIMELINE) {
            $range = $this->timelineRange($current);
            if ($range === null) {
                return BoardAutomationActionOutcome::skipped("\"{$column->label}\" has no timeline to push.");
            }
            $next = ['start' => $shift($range['start']), 'end' => $shift($range['end'])];
        } else {
            $date = $this->dateString($current);
            if ($date === null) {
                return BoardAutomationActionOutcome::skipped("\"{$column->label}\" has no date to push.");
            }
            $next = $shift($date);
        }

        $this->writeValue($subject, $column, $next, $actor);
        $shown = $this->renderer->displayValue($column, $next);
        $this->log($automation, $subject, $actor, "pushed \"{$column->label}\" to {$shown} on \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success("Pushed \"{$column->label}\" to {$shown}.");
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function setDateFromColumn(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, BoardColumn::TYPE_DATE);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $source = $this->resolveColumnTarget($automation, $params, $item, [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE], 'source_column_id');
        if (is_string($source)) {
            return BoardAutomationActionOutcome::skipped($source);
        }
        [$source_column, $source_subject] = $source;

        $base = $this->dateEnd($this->currentValue($source_subject, $source_column));
        if ($base === null) {
            return BoardAutomationActionOutcome::skipped("\"{$source_column->label}\" has no date to start from.");
        }

        $days = (int) ($params['offset_days'] ?? 0);
        if (! empty($params['number_column_id'])) {
            $number = $this->resolveColumnTarget($automation, ['number_column_id' => $params['number_column_id']], $item, BoardColumn::TYPE_NUMBER, 'number_column_id');
            if (is_string($number)) {
                return BoardAutomationActionOutcome::skipped($number);
            }
            [$number_column, $number_subject] = $number;
            $value = $this->currentValue($number_subject, $number_column);
            if (! is_numeric($value)) {
                return BoardAutomationActionOutcome::skipped("\"{$number_column->label}\" has no number of days.");
            }
            $days += ((int) ($params['number_sign'] ?? 1) < 0 ? -1 : 1) * (int) round((float) $value);
        }

        if (abs($days) > self::MAX_DATE_SHIFT_DAYS) {
            return BoardAutomationActionOutcome::skipped('The date would move more than ten years, so it was left as it was.');
        }

        $next = $this->moveDateString($base, $days, 'days');
        if ($this->valuesAreEqual($this->dateString($this->currentValue($subject, $column)), $next)) {
            return BoardAutomationActionOutcome::skipped("\"{$column->label}\" was already {$next}.");
        }

        $this->writeValue($subject, $column, $next, $actor);
        $this->log($automation, $subject, $actor, "set \"{$column->label}\" to {$next} on \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success("Set \"{$column->label}\" to {$next}.");
    }

    /**
     * Moves the target so it starts `gap_days` after the source ends, keeping a timeline's length.
     * A target that already starts late enough is left alone.
     *
     * @param  array<string, mixed>  $params
     */
    private function ensureDateAfter(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE]);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $source = $this->resolveColumnTarget($automation, $params, $item, [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE], 'source_column_id');
        if (is_string($source)) {
            return BoardAutomationActionOutcome::skipped($source);
        }
        [$source_column, $source_subject] = $source;

        $source_end = $this->dateEnd($this->currentValue($source_subject, $source_column));
        if ($source_end === null) {
            return BoardAutomationActionOutcome::skipped("\"{$source_column->label}\" has no date yet.");
        }

        $earliest = CarbonImmutable::parse(substr($source_end, 0, 10))->addDays(max(0, (int) ($params['gap_days'] ?? 1)));
        $current = $this->currentValue($subject, $column);

        if ($column->type === BoardColumn::TYPE_TIMELINE) {
            $range = $this->timelineRange($current);
            if ($range === null) {
                $next = ['start' => $earliest->toDateString(), 'end' => $earliest->toDateString()];
            } else {
                $start = CarbonImmutable::parse(substr($range['start'], 0, 10));
                if ($start->greaterThanOrEqualTo($earliest)) {
                    return BoardAutomationActionOutcome::skipped("\"{$column->label}\" already starts after \"{$source_column->label}\".");
                }
                $days = (int) $start->diffInDays($earliest);
                $next = ['start' => $this->moveDateString($range['start'], $days, 'days'), 'end' => $this->moveDateString($range['end'], $days, 'days')];
            }
        } else {
            $date = $this->dateString($current);
            if ($date !== null && CarbonImmutable::parse(substr($date, 0, 10))->greaterThanOrEqualTo($earliest)) {
                return BoardAutomationActionOutcome::skipped("\"{$column->label}\" already starts after \"{$source_column->label}\".");
            }
            $next = $earliest->toDateString().($date !== null ? substr($date, 10) : '');
        }

        $this->writeValue($subject, $column, $next, $actor);
        $shown = $this->renderer->displayValue($column, $next);
        $this->log($automation, $subject, $actor, "moved \"{$column->label}\" to {$shown} on \"{$subject->name}\" to keep it after \"{$source_column->label}\"");

        return BoardAutomationActionOutcome::success("Moved \"{$column->label}\" to {$shown} so it starts after \"{$source_column->label}\".");
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function setTimeline(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, BoardColumn::TYPE_TIMELINE);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $duration = max(1, min(self::MAX_DATE_SHIFT_DAYS, (int) ($params['duration_days'] ?? 7)));
        if (empty($params['use_working_days'])) {
            $start = Carbon::today()->addDays((int) ($params['start_offset_days'] ?? 0));
            $next = ['start' => $start->toDateString(), 'end' => $start->copy()->addDays($duration - 1)->toDateString()];
        } else {
            // Counted in working days: the timeline starts on a working day and covers `duration` of them.
            $calendar = BoardAutomationSetting::forBoard($automation->board_id)->calendar();
            $start = $calendar->addWorkingDays(CarbonImmutable::today(), (int) ($params['start_offset_days'] ?? 0));
            $next = ['start' => $start->toDateString(), 'end' => $calendar->addWorkingDays($start, $duration - 1)->toDateString()];
        }

        if ($this->timelineRange($this->currentValue($subject, $column)) === $next) {
            return BoardAutomationActionOutcome::skipped("\"{$column->label}\" already had that timeline.");
        }

        $this->writeValue($subject, $column, $next, $actor);
        $this->log($automation, $subject, $actor, "set \"{$column->label}\" to {$next['start']} to {$next['end']} on \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success("Set \"{$column->label}\" to {$next['start']} to {$next['end']}.");
    }

    // ── Date helpers ──────────────────────────────────────────────────────────

    /**
     * Moves `YYYY-MM-DD` (or `YYYY-MM-DDTHH:mm`) by `$amount` units, keeping any time of day. Months
     * never overflow, Jan 31 plus one month is Feb 28.
     */
    private function moveDateString(string $value, int $amount, string $unit): string
    {
        $date = CarbonImmutable::parse(substr($value, 0, 10));
        $moved = match ($unit) {
            'weeks' => $date->addWeeks($amount),
            'months' => $date->addMonthsNoOverflow($amount),
            default => $date->addDays($amount),
        };

        return $moved->toDateString().substr($value, 10);
    }

    /**
     * A date cell's value when it holds a valid date, null otherwise.
     */
    private function dateString(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        try {
            CarbonImmutable::parse(substr($value, 0, 10));
        } catch (Throwable) {
            return null;
        }

        return $value;
    }

    /**
     * @return array{start: string, end: string}|null
     */
    private function timelineRange(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $start = $this->dateString($value['start'] ?? $value[0] ?? null);
        $end = $this->dateString($value['end'] ?? $value[1] ?? null);
        if ($start === null && $end === null) {
            return null;
        }

        return ['start' => $start ?? $end, 'end' => $end ?? $start];
    }

    /**
     * The last day a date or timeline value covers.
     */
    private function dateEnd(mixed $value): ?string
    {
        return $this->dateString($value) ?? $this->timelineRange($value)['end'] ?? null;
    }
}
