<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The calendar of a recurring automation (`trigger_config.schedule`):
 *
 * - `frequency`: `daily`, `weekly` or `monthly`
 * - `weekdays`: ISO weekdays (1 Monday to 7 Sunday) a weekly schedule runs on
 * - `day_of_month`: 1 to 31, a monthly schedule runs on the last day of shorter months
 * - `time`: `HH:MM` in `timezone` (an IANA name, the creator's browser zone)
 */
class AutomationSchedule
{
    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const DEFAULT_TIME = '09:00';

    /** How far back {@see latestOccurrence()} looks, one month covers every frequency. */
    private const LOOKBACK_DAYS = 32;

    /**
     * @return array<int, string>
     */
    public static function frequencies(): array
    {
        return [self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY];
    }

    /**
     * An IANA zone name, falling back to the app's zone when it is missing or unknown.
     */
    public static function timezone(?string $timezone): string
    {
        return $timezone && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : (string) config('app.timezone', 'UTC');
    }

    /**
     * `[hour, minute]` of an `HH:MM` time, the default time when it is malformed.
     *
     * @return array{0: int, 1: int}
     */
    public static function parseTime(?string $time): array
    {
        if (! is_string($time) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches) !== 1) {
            [$hour, $minute] = explode(':', self::DEFAULT_TIME);

            return [(int) $hour, (int) $minute];
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * The most recent moment the schedule was due at or before `$now`, null when it has no
     * occurrence in the last month (a weekly schedule without weekdays, for example).
     *
     * @param  array<string, mixed>  $schedule
     */
    public static function latestOccurrence(array $schedule, CarbonInterface $now): ?CarbonImmutable
    {
        $timezone = self::timezone($schedule['timezone'] ?? null);
        [$hour, $minute] = self::parseTime($schedule['time'] ?? null);
        $local_now = CarbonImmutable::instance($now)->setTimezone($timezone);

        for ($offset = 0; $offset <= self::LOOKBACK_DAYS; $offset++) {
            $day = $local_now->subDays($offset);
            if (! self::runsOn($schedule, $day)) {
                continue;
            }

            $occurrence = $day->setTime($hour, $minute);
            if ($occurrence->lessThanOrEqualTo($local_now)) {
                return $occurrence;
            }
        }

        return null;
    }

    /**
     * Whether the schedule has a run on `$day`'s date.
     *
     * @param  array<string, mixed>  $schedule
     */
    public static function runsOn(array $schedule, CarbonImmutable $day): bool
    {
        return match ($schedule['frequency'] ?? null) {
            self::FREQUENCY_DAILY => true,
            self::FREQUENCY_WEEKLY => in_array($day->dayOfWeekIso, array_map('intval', (array) ($schedule['weekdays'] ?? [])), true),
            self::FREQUENCY_MONTHLY => $day->day === min(max(1, (int) ($schedule['day_of_month'] ?? 1)), $day->daysInMonth),
            default => false,
        };
    }
}
