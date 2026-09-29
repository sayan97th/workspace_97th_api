<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A board's working calendar: which weekdays are worked (ISO, 1 Monday to 7 Sunday) and which
 * dates are holidays. Date triggers and date actions that skip non working days count with it.
 */
final class WorkingCalendar
{
    /** A calendar with no working day at all would loop forever, so a search gives up after this. */
    private const MAX_SEARCH_DAYS = 3660;

    /** @var array<int, true> */
    private array $workdays;

    /** @var array<string, true> */
    private array $holidays;

    /**
     * @param  array<int, int|string>  $workdays
     * @param  array<int, string>  $holidays
     */
    public function __construct(array $workdays, array $holidays = [])
    {
        $days = array_values(array_filter(array_map('intval', $workdays), fn (int $day) => $day >= 1 && $day <= 7));
        $this->workdays = array_fill_keys($days ?: [1, 2, 3, 4, 5], true);
        $this->holidays = array_fill_keys(array_values(array_filter($holidays, fn ($date) => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1)), true);
    }

    public function isWorkingDay(CarbonImmutable $date): bool
    {
        return isset($this->workdays[$date->dayOfWeekIso]) && ! isset($this->holidays[$date->toDateString()]);
    }

    /**
     * Moves `$date` by `$days` working days, forward when positive and back when negative. A zero
     * move still lands on a working day, the next one when `$date` is not.
     */
    public function addWorkingDays(CarbonImmutable $date, int $days): CarbonImmutable
    {
        $step = $days < 0 ? -1 : 1;
        $remaining = abs($days);
        $current = $date;
        $guard = 0;

        if ($remaining === 0) {
            return $this->nextWorkingDay($date);
        }

        while ($remaining > 0 && $guard++ < self::MAX_SEARCH_DAYS) {
            $current = $current->addDays($step);
            if ($this->isWorkingDay($current)) {
                $remaining--;
            }
        }

        return $current;
    }

    /**
     * `$date` itself when it is a working day, otherwise the next one.
     */
    public function nextWorkingDay(CarbonImmutable $date): CarbonImmutable
    {
        return $this->searchFrom($date, 1);
    }

    /**
     * `$date` itself when it is a working day, otherwise the one before.
     */
    public function previousWorkingDay(CarbonImmutable $date): CarbonImmutable
    {
        return $this->searchFrom($date, -1);
    }

    /**
     * How many working days lie between two dates, counting `$to` but not `$from`, negative when
     * `$to` comes first.
     */
    public function workingDaysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        if ($from->equalTo($to)) {
            return 0;
        }

        $step = $to->greaterThan($from) ? 1 : -1;
        $count = 0;
        $current = $from;
        $guard = 0;
        while (! $current->equalTo($to) && $guard++ < self::MAX_SEARCH_DAYS) {
            $current = $current->addDays($step);
            if ($this->isWorkingDay($current)) {
                $count += $step;
            }
        }

        return $count;
    }

    private function searchFrom(CarbonImmutable $date, int $step): CarbonImmutable
    {
        $current = $date;
        $guard = 0;
        while (! $this->isWorkingDay($current) && $guard++ < self::MAX_SEARCH_DAYS) {
            $current = $current->addDays($step);
        }

        return $current;
    }
}
