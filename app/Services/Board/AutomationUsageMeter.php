<?php

namespace App\Services\Board;

use App\Models\AccountSetting;
use App\Models\AutomationUsageMonth;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationRunLog;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Notification\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The account's monthly automation action quota, like monday.com's "actions per month".
 *
 * Every action an automation actually performs (one that succeeded or failed, not one it skipped,
 * and never a "wait" step or a test run) counts once against the calendar month it ran in. The
 * limit lives on {@see AccountSetting::$automation_monthly_action_limit}, null means no limit. Once
 * the month's count reaches the limit, {@see BoardAutomationService} skips every further action
 * with a message until the next month starts or an admin raises the limit.
 *
 * Admins are notified once a month when the count crosses {@see self::WARNING_PERCENTS}.
 */
class AutomationUsageMeter
{
    /** Usage levels, in percent of the limit, that notify the account admins, once each per month. */
    public const WARNING_PERCENTS = [80, 100];

    /** The largest limit an admin may set. */
    public const MAX_LIMIT = 10_000_000;

    /** How many boards the usage summary breaks the month down by. */
    private const TOP_BOARDS = 5;

    public function __construct(private readonly NotificationService $notification_service) {}

    /**
     * The month a count belongs to, `YYYY-MM` in the app's time zone.
     */
    public static function monthKey(?CarbonImmutable $now = null): string
    {
        return ($now ?? CarbonImmutable::now())->format('Y-m');
    }

    public function limit(): ?int
    {
        $limit = AccountSetting::current()->automation_monthly_action_limit;

        return $limit === null ? null : (int) $limit;
    }

    public function used(?string $month = null): int
    {
        return (int) AutomationUsageMonth::where('month', $month ?? self::monthKey())->value('action_count');
    }

    /**
     * Whether this month's actions are used up, so no further action may run.
     */
    public function isExhausted(): bool
    {
        $limit = $this->limit();

        return $limit !== null && $this->used() >= $limit;
    }

    /**
     * The message a skipped action carries once the quota is used up.
     */
    public function exhaustedMessage(): string
    {
        $limit = $this->limit() ?? 0;
        $resets_on = CarbonImmutable::now()->startOfMonth()->addMonth()->format('F j');

        return "Skipped, the account used all {$limit} automation actions of this month. Automations run again on {$resets_on}, or sooner once an admin raises the limit.";
    }

    /**
     * Counts one performed action and warns the admins when the month crosses a warning level.
     * Counting is best effort, a failure is reported and never breaks the automation.
     */
    public function recordAction(): void
    {
        $month = self::monthKey();

        try {
            $this->ensureMonth($month);
            AutomationUsageMonth::where('month', $month)->increment('action_count');
            $this->warnIfNeeded($month);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * What the Automations centers show: this month's count against the limit, when it resets
     * and the boards that used the most, read from the run history.
     *
     * @param  Collection<int, int>|null  $visible_board_ids  the boards the viewer may see, the breakdown leaves the rest out
     * @return array<string, mixed>
     */
    public function summary(?Collection $visible_board_ids = null, bool $can_manage = false): array
    {
        $now = CarbonImmutable::now();

        $counts = BoardAutomationRunLog::query()
            ->whereIn('status', [BoardAutomationRunLog::STATUS_SUCCESS, BoardAutomationRunLog::STATUS_FAILED])
            ->where('action_type', '!=', BoardAutomation::ACTION_WAIT)
            ->where('created_at', '>=', $now->startOfMonth())
            ->when($visible_board_ids !== null, fn ($query) => $query->whereIn('board_id', $visible_board_ids))
            ->selectRaw('board_id, COUNT(*) as action_count')
            ->groupBy('board_id')
            ->orderByDesc('action_count')
            ->limit(self::TOP_BOARDS)
            ->toBase()
            ->get();
        $board_names = WorkspaceNavigationItem::whereIn('id', $counts->pluck('board_id'))->pluck('label', 'id');
        $top_boards = $counts->map(fn (object $row) => [
            'board_id' => (int) $row->board_id,
            'board_name' => (string) ($board_names[$row->board_id] ?? 'Deleted board'),
            'action_count' => (int) $row->action_count,
        ])->values()->all();

        return [
            ...$this->quota(),
            'warning_percents' => self::WARNING_PERCENTS,
            'top_boards' => $top_boards,
            'can_manage' => $can_manage,
        ];
    }

    /**
     * This month's count against the limit, without the board breakdown.
     *
     * @return array{month: string, used: int, limit: int|null, remaining: int|null, percent: int|null, is_exhausted: bool, resets_on: string}
     */
    public function quota(): array
    {
        $now = CarbonImmutable::now();
        $used = $this->used(self::monthKey($now));
        $limit = $this->limit();

        return [
            'month' => self::monthKey($now),
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'percent' => $limit === null || $limit === 0 ? null : (int) min(100, floor($used * 100 / $limit)),
            'is_exhausted' => $limit !== null && $used >= $limit,
            'resets_on' => $now->startOfMonth()->addMonth()->toDateString(),
        ];
    }

    private function ensureMonth(string $month): void
    {
        if (AutomationUsageMonth::where('month', $month)->exists()) {
            return;
        }

        try {
            AutomationUsageMonth::create(['month' => $month, 'action_count' => 0, 'warned_percent' => 0]);
        } catch (UniqueConstraintViolationException) {
            // Another run created the month first, the increment that follows still counts.
        }
    }

    /**
     * Claims the highest warning level the month crossed, so exactly one run sends it.
     */
    private function warnIfNeeded(string $month): void
    {
        $limit = $this->limit();
        if ($limit === null || $limit === 0) {
            return;
        }

        $used = $this->used($month);
        $crossed = collect(self::WARNING_PERCENTS)->filter(fn (int $percent) => $used * 100 >= $percent * $limit)->max();
        if ($crossed === null) {
            return;
        }

        $claimed = AutomationUsageMonth::where('month', $month)->where('warned_percent', '<', $crossed)->update(['warned_percent' => $crossed]);
        if ($claimed === 0) {
            return;
        }

        $this->notifyAdmins((int) $crossed, $used, $limit);
    }

    private function notifyAdmins(int $percent, int $used, int $limit): void
    {
        $resets_on = CarbonImmutable::now()->startOfMonth()->addMonth()->format('F j');
        $label = $percent >= 100
            ? 'Automations used all of this month\'s actions'
            : "Automations used {$percent}% of this month's actions";
        $message = $percent >= 100
            ? "{$used} of {$limit} actions used. Automations stop acting until {$resets_on}, raise the limit to keep them running."
            : "{$used} of {$limit} actions used. The count starts again on {$resets_on}.";

        $admins = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['super_admin', 'admin']))
            ->get();

        foreach ($admins as $admin) {
            try {
                $this->notification_service->notify(
                    recipient: $admin,
                    actor: null,
                    type: Notification::TYPE_AUTOMATION,
                    board: null,
                    action_label: $label,
                    action_target: $message,
                    link: '/automations',
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
