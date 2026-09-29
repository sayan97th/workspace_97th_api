<?php

namespace App\Models;

use App\Support\WorkingCalendar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Board wide automation settings, one row per board created on first save.
 *
 * - `paused_at`: set by "Pause all automations", no automation of the board runs while it is set.
 *   Waiting steps stay pending and scheduled ones catch up on their next occurrence.
 * - `workdays` (ISO weekdays, 1 Monday to 7 Sunday) and `holidays` (`YYYY-MM-DD`): the working
 *   calendar date triggers and date actions use when told to skip non working days, see
 *   {@see WorkingCalendar}. Monday to Friday and no holidays until changed.
 * - `auto_pause_after_failures`: an automation that fails this many runs in a row is paused and its
 *   owner told. Null means {@see self::DEFAULT_AUTO_PAUSE_AFTER_FAILURES}, 0 never pauses.
 *
 * @property int $id
 * @property int $board_id
 * @property Carbon|null $paused_at
 * @property int|null $paused_by_id
 * @property array<int, int>|null $workdays
 * @property array<int, string>|null $holidays
 * @property int|null $auto_pause_after_failures
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $pausedBy
 */
#[Fillable(['board_id', 'paused_at', 'paused_by_id', 'workdays', 'holidays', 'auto_pause_after_failures'])]
class BoardAutomationSetting extends Model
{
    public const DEFAULT_WORKDAYS = [1, 2, 3, 4, 5];

    public const MAX_HOLIDAYS = 100;

    public const DEFAULT_AUTO_PAUSE_AFTER_FAILURES = 5;

    public const MAX_AUTO_PAUSE_AFTER_FAILURES = 100;

    /**
     * The saved settings of a board, or unsaved defaults.
     */
    public static function forBoard(int $board_id): self
    {
        return self::firstWhere('board_id', $board_id) ?? new self(['board_id' => $board_id]);
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /**
     * How many failed runs in a row pause an automation, 0 for never.
     */
    public function autoPauseThreshold(): int
    {
        return $this->auto_pause_after_failures ?? self::DEFAULT_AUTO_PAUSE_AFTER_FAILURES;
    }

    public function calendar(): WorkingCalendar
    {
        return new WorkingCalendar($this->workdays ?: self::DEFAULT_WORKDAYS, (array) ($this->holidays ?? []));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pausedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paused_at' => 'datetime',
            'workdays' => 'array',
            'holidays' => 'array',
            'auto_pause_after_failures' => 'integer',
        ];
    }
}
