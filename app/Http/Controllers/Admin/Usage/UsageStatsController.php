<?php

namespace App\Http\Controllers\Admin\Usage;

use App\Http\Controllers\Controller;
use App\Models\BoardItem;
use App\Models\BoardItemAttachment;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/admin/usage?from=Y-m-d&to=Y-m-d
 *
 * Administration > Usage stats, the monday.com usage dashboard: KPI totals, a daily active
 * users trend, and the most active boards and people for the chosen range.
 *
 * "Active" means the person did something recordable that day: signed in, changed an item
 * value, posted an update, or triggered a board or audit log entry. Board visits are not
 * counted because the visit table only keeps each person's latest visit per board.
 */
class UsageStatsController extends Controller
{
    private const DEFAULT_RANGE_DAYS = 30;

    private const MAX_RANGE_DAYS = 366;

    private const TOP_LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $activity = $this->activityUnion($from, $to);

        $daily = DB::query()
            ->fromSub($activity, 'activity')
            ->selectRaw('activity_day, COUNT(DISTINCT user_id) AS active_users')
            ->groupBy('activity_day')
            ->pluck('active_users', 'activity_day');

        $trend = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $trend[] = ['date' => $key, 'active_users' => (int) ($daily[$key] ?? 0)];
        }

        $active_users = (int) DB::query()->fromSub($activity, 'activity')->selectRaw('COUNT(DISTINCT user_id) AS total')->value('total');

        $range_end = $to->copy()->endOfDay();

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => [
                'total_users' => User::query()->where('is_active', true)->count(),
                'deactivated_users' => User::withTrashed()->where(fn ($q) => $q->where('is_active', false)->orWhereNotNull('deleted_at'))->count(),
                'active_users' => $active_users,
                'new_users' => User::query()->whereBetween('created_at', [$from, $range_end])->count(),
                'total_boards' => WorkspaceNavigationItem::query()->boards()->notArchived()->count(),
                'boards_created' => WorkspaceNavigationItem::query()->boards()->whereBetween('created_at', [$from, $range_end])->count(),
                'total_items' => BoardItem::query()->count(),
                'items_created' => BoardItem::query()->whereBetween('created_at', [$from, $range_end])->count(),
                'updates_posted' => BoardItemComment::query()->whereBetween('created_at', [$from, $range_end])->count(),
                'files_uploaded' => BoardItemAttachment::query()->whereBetween('created_at', [$from, $range_end])->count(),
                'storage_bytes' => (int) BoardItemAttachment::query()->sum('size_bytes'),
            ],
            'daily_active_users' => $trend,
            'top_boards' => $this->topBoards($from, $range_end),
            'top_users' => $this->topUsers($activity),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $parse = function (?string $value): ?Carbon {
            if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }

            try {
                return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        $to = $parse($request->query('to')) ?? now()->startOfDay();
        $from = $parse($request->query('from')) ?? $to->copy()->subDays(self::DEFAULT_RANGE_DAYS - 1);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $to->copy()->subDays(self::MAX_RANGE_DAYS);
        }

        return [$from, $to];
    }

    /**
     * One `(user_id, activity_day)` row per recorded action in the range, across every table
     * that attributes an action to a person.
     */
    private function activityUnion(Carbon $from, Carbon $to): QueryBuilder
    {
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $source = fn (string $table, string $column = 'created_at') => DB::table($table)
            ->selectRaw("user_id, DATE({$column}) AS activity_day")
            ->whereNotNull('user_id')
            ->whereBetween($column, $range);

        return $source('user_sessions')
            ->unionAll($source('user_sessions', 'last_used_at'))
            ->unionAll($source('board_activity_logs'))
            ->unionAll($source('board_item_activities'))
            ->unionAll($source('board_item_comments'))
            ->unionAll($source('audit_logs'));
    }

    /**
     * Boards with the most item changes, updates and board log entries in the range.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topBoards(Carbon $from, Carbon $to): array
    {
        $range = [$from, $to];

        $events = DB::table('board_activity_logs')->select('board_id')->whereBetween('created_at', $range)
            ->unionAll(
                DB::table('board_item_activities')
                    ->join('board_items', 'board_items.id', '=', 'board_item_activities.item_id')
                    ->select('board_items.board_id')
                    ->whereBetween('board_item_activities.created_at', $range)
            )
            ->unionAll(
                DB::table('board_item_comments')
                    ->join('board_items', 'board_items.id', '=', 'board_item_comments.item_id')
                    ->select('board_items.board_id')
                    ->whereBetween('board_item_comments.created_at', $range)
            );

        $counts = DB::query()
            ->fromSub($events, 'events')
            ->selectRaw('board_id, COUNT(*) AS events_count')
            ->groupBy('board_id')
            ->orderByDesc('events_count')
            ->limit(self::TOP_LIMIT)
            ->pluck('events_count', 'board_id');

        $boards = WorkspaceNavigationItem::query()
            ->with('workspace:id,name')
            ->whereIn('id', $counts->keys())
            ->get()
            ->keyBy('id');

        return $counts->map(function ($events_count, $board_id) use ($boards) {
            $board = $boards->get($board_id);

            return $board ? [
                'id' => $board->id,
                'label' => $board->label,
                'workspace' => $board->workspace?->name,
                'events_count' => (int) $events_count,
            ] : null;
        })->filter()->values()->all();
    }

    /**
     * People with the most recorded actions in the range.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topUsers(QueryBuilder $activity): array
    {
        $counts = DB::query()
            ->fromSub($activity, 'activity')
            ->selectRaw('user_id, COUNT(*) AS events_count, COUNT(DISTINCT activity_day) AS active_days')
            ->groupBy('user_id')
            ->orderByDesc('events_count')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->keyBy('user_id');

        $users = User::withTrashed()->whereIn('id', $counts->keys())->get()->keyBy('id');

        return $counts->map(function ($row, $user_id) use ($users) {
            $user = $users->get($user_id);

            return $user ? [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'profile_photo_url' => $user->profile_photo_url,
                'is_deactivated' => $user->is_deactivated,
                'events_count' => (int) $row->events_count,
                'active_days' => (int) $row->active_days,
            ] : null;
        })->filter()->values()->all();
    }
}
