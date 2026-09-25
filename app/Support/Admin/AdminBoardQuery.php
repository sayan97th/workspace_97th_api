<?php

namespace App\Support\Admin;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Builds the account wide board listing behind Administration > Content directory and
 * Administration > Tidy up (which is the same listing narrowed to inactive boards), shared by
 * the paginated list, the CSV export and the bulk actions.
 *
 * "Last activity" is the newest of: the board row itself changing (rename, move, settings),
 * any of its items changing, and any board activity log entry. It is computed in SQL so it can
 * be filtered and sorted on without loading every board.
 *
 * Supported query parameters:
 *  - `search`: board name contains.
 *  - `workspace`: workspace ids, any of.
 *  - `owner`: user ids and/or `none`, any of.
 *  - `board_type`: `main`, `private`, `shareable`, any of.
 *  - `status`: `active`, `archived`, any of (default: both).
 *  - `items_min`, `items_max`: item count range.
 *  - `created_from`, `created_to`, `activity_from`, `activity_to` (Y-m-d).
 *  - `inactive_days`: only boards with no activity in the last N days (Tidy up).
 *  - `ids`: restricts to the given board ids (the selected rows of an export).
 */
class AdminBoardQuery
{
    public const ALLOWED_SORT_FIELDS = ['label', 'workspace', 'owner', 'items_count', 'created_at', 'last_activity_at'];

    private const TABLE = 'workspace_navigation_items';

    /**
     * @return Builder<WorkspaceNavigationItem>
     */
    public static function fromRequest(Request $request): Builder
    {
        $last_activity = self::lastActivityExpression();
        $items_count = self::itemsCountExpression();

        $query = WorkspaceNavigationItem::query()
            ->boards()
            ->select(self::TABLE.'.*')
            ->selectRaw("{$last_activity} AS last_activity_at")
            ->selectRaw("{$items_count} AS items_count")
            ->with(['workspace:id,name', 'owner:id,first_name,last_name,profile_photo_path,is_active,deleted_at', 'creator:id,first_name,last_name']);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(self::TABLE.'.label', 'LIKE', "%{$search}%");
        }

        $workspace_ids = self::intList(AdminUserQuery::listParam($request, 'workspace'));
        if ($workspace_ids !== []) {
            $query->whereIn(self::TABLE.'.workspace_id', $workspace_ids);
        }

        $owners = AdminUserQuery::listParam($request, 'owner');
        if ($owners !== []) {
            $owner_ids = self::intList($owners);
            $query->where(function (Builder $q) use ($owners, $owner_ids) {
                if (in_array('none', $owners, true)) {
                    $q->orWhereNull(self::TABLE.'.owner_id');
                }
                if ($owner_ids !== []) {
                    $q->orWhereIn(self::TABLE.'.owner_id', $owner_ids);
                }
            });
        }

        $board_types = AdminUserQuery::listParam($request, 'board_type', [
            WorkspaceNavigationItem::BOARD_TYPE_MAIN,
            WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
            WorkspaceNavigationItem::BOARD_TYPE_SHAREABLE,
        ]);
        if ($board_types !== []) {
            $query->whereIn(self::TABLE.'.board_type', $board_types);
        }

        $statuses = AdminUserQuery::listParam($request, 'status', ['active', 'archived']);
        if (count($statuses) === 1) {
            $query->where(self::TABLE.'.is_archived', $statuses[0] === 'archived');
        }

        if (is_numeric($request->query('items_min'))) {
            $query->whereRaw("{$items_count} >= ?", [(int) $request->query('items_min')]);
        }
        if (is_numeric($request->query('items_max'))) {
            $query->whereRaw("{$items_count} <= ?", [(int) $request->query('items_max')]);
        }

        if ($from = AdminUserQuery::dateParam($request, 'created_from')) {
            $query->where(self::TABLE.'.created_at', '>=', $from->startOfDay());
        }
        if ($to = AdminUserQuery::dateParam($request, 'created_to')) {
            $query->where(self::TABLE.'.created_at', '<=', $to->endOfDay());
        }
        if ($from = AdminUserQuery::dateParam($request, 'activity_from')) {
            $query->whereRaw("{$last_activity} >= ?", [$from->startOfDay()->toDateTimeString()]);
        }
        if ($to = AdminUserQuery::dateParam($request, 'activity_to')) {
            $query->whereRaw("{$last_activity} <= ?", [$to->endOfDay()->toDateTimeString()]);
        }

        $inactive_days = (int) $request->query('inactive_days', 0);
        if ($inactive_days > 0) {
            $query->where(self::TABLE.'.is_archived', false)
                ->whereRaw("{$last_activity} < ?", [now()->subDays($inactive_days)->toDateTimeString()]);
        }

        $ids = self::intList(AdminUserQuery::listParam($request, 'ids'));
        if ($ids !== []) {
            $query->whereIn(self::TABLE.'.id', $ids);
        }

        return $query;
    }

    public static function applySort(Builder $query, string $sort_field, string $sort_direction): void
    {
        switch ($sort_field) {
            case 'label':
                $query->orderBy(self::TABLE.'.label', $sort_direction);
                break;

            case 'workspace':
                $query->orderBy(
                    DB::table('workspaces')->select('name')->whereColumn('workspaces.id', self::TABLE.'.workspace_id'),
                    $sort_direction,
                );
                break;

            case 'owner':
                $query->orderByRaw('CASE WHEN '.self::TABLE.'.owner_id IS NULL THEN 1 ELSE 0 END')
                    ->orderBy(
                        DB::table('users')->select('first_name')->whereColumn('users.id', self::TABLE.'.owner_id'),
                        $sort_direction,
                    );
                break;

            case 'items_count':
                $query->orderByRaw(self::itemsCountExpression().' '.$sort_direction);
                break;

            case 'created_at':
                $query->orderBy(self::TABLE.'.created_at', $sort_direction);
                break;

            default:
                $query->orderByRaw(self::lastActivityExpression().' '.$sort_direction);
                break;
        }

        $query->orderBy(self::TABLE.'.id', $sort_direction);
    }

    /**
     * Newest of the board's own `updated_at`, its items' `updated_at`, and its activity log.
     * Each subquery falls back to the board's own timestamp, so a board with no items or no
     * log entries still gets a value. MySQL spells the scalar max `GREATEST`, SQLite `MAX`.
     */
    public static function lastActivityExpression(): string
    {
        $board = self::TABLE;
        $greatest = DB::connection()->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';

        return "{$greatest}("
            ."{$board}.updated_at, "
            ."COALESCE((SELECT MAX(board_items.updated_at) FROM board_items WHERE board_items.board_id = {$board}.id), {$board}.updated_at), "
            ."COALESCE((SELECT MAX(board_activity_logs.created_at) FROM board_activity_logs WHERE board_activity_logs.board_id = {$board}.id), {$board}.updated_at)"
            .')';
    }

    public static function itemsCountExpression(): string
    {
        $board = self::TABLE;

        return "(SELECT COUNT(*) FROM board_items WHERE board_items.board_id = {$board}.id AND board_items.deleted_at IS NULL AND board_items.parent_id IS NULL)";
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, int>
     */
    private static function intList(array $values): array
    {
        return array_map('intval', array_values(array_filter($values, 'ctype_digit')));
    }
}
