<?php

namespace App\Services\Board;

use App\Models\BoardActivityLog;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemActivity;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads a board's Activity log the way monday.com shows it: one timeline,
 * newest first, that merges every cell change made to the board's items
 * ({@see BoardItemActivity}) with the structural events of the board, its
 * items, groups and columns ({@see BoardActivityLog}).
 *
 * The two tables are paged together with a keyset cursor (time, source, id),
 * so a page never needs a count or an offset and stays fast on a long log.
 * Every entry comes back in one shape (see {@see present()}), with status
 * label colors, people and group colors already resolved, and whether the
 * entry can still be undone.
 */
class BoardActivityFeed
{
    public const SOURCE_CELL = 'cell';

    public const SOURCE_BOARD = 'board';

    /** Tie breaker between the two sources for entries made in the same second. */
    private const SOURCE_RANK = [self::SOURCE_CELL => 1, self::SOURCE_BOARD => 0];

    /** "Filter log" event types, mapped to the board log actions each one covers. */
    public const BOARD_EVENT_TYPES = [
        'item_created' => [BoardActivityLog::ACTION_ITEM_CREATED, BoardActivityLog::ACTION_ITEM_DUPLICATED, BoardActivityLog::ACTION_ITEM_RECURRED],
        'item_moved' => [BoardActivityLog::ACTION_ITEM_MOVED],
        'item_archived' => [BoardActivityLog::ACTION_ITEM_ARCHIVED],
        'item_deleted' => [BoardActivityLog::ACTION_ITEM_TRASHED, BoardActivityLog::ACTION_ITEM_DELETED],
        'item_restored' => [BoardActivityLog::ACTION_ITEM_RESTORED],
        'groups' => [
            BoardActivityLog::ACTION_GROUP_CREATED, BoardActivityLog::ACTION_GROUP_RENAMED, BoardActivityLog::ACTION_GROUP_ARCHIVED,
            BoardActivityLog::ACTION_GROUP_RESTORED, BoardActivityLog::ACTION_GROUP_DELETED, BoardActivityLog::ACTION_GROUP_DUPLICATED,
        ],
        'columns' => [
            BoardActivityLog::ACTION_COLUMN_CREATED, BoardActivityLog::ACTION_COLUMN_RENAMED,
            BoardActivityLog::ACTION_COLUMN_DELETED, BoardActivityLog::ACTION_COLUMN_DUPLICATED,
        ],
        'board' => [
            BoardActivityLog::ACTION_CREATED, BoardActivityLog::ACTION_RENAMED, BoardActivityLog::ACTION_TYPE_CHANGED,
            BoardActivityLog::ACTION_DUPLICATED, BoardActivityLog::ACTION_ARCHIVED, BoardActivityLog::ACTION_UNARCHIVED,
            BoardActivityLog::ACTION_DELETED, BoardActivityLog::ACTION_PERMISSIONS_CHANGED,
        ],
        'automations' => [BoardActivityLog::ACTION_AUTOMATION_RAN],
    ];

    /** Most cell changes one filtered request scans by default, see {@see page()}. */
    public const SCAN_WINDOW = 20000;

    /** Most items an item name search matches, keeps the id list short on a huge board. */
    private const MAX_SEARCH_ITEMS = 2000;

    /** "Filter log" event types answered by cell change rows. */
    public const CELL_EVENT_TYPES = ['column_values', 'item_name', 'item_moved'];

    /** Column types whose values are drawn as colored label chips. */
    private const LABEL_COLUMN_TYPES = [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL];

    /**
     * @param  int  $scan_window  Most cell changes one filtered request scans, smaller in tests.
     */
    public function __construct(private readonly int $scan_window = self::SCAN_WINDOW) {}

    /**
     * One page of the log, newest first.
     *
     * Filters (all optional): `user_ids`, `column_ids`, `group_ids`,
     * `event_types` (keys of {@see BOARD_EVENT_TYPES} and
     * {@see CELL_EVENT_TYPES}), `search`, `from` and `to` (dates), and
     * `automation_only` (only what automations did).
     *
     * A filtered request scans at most `$scan_window` cell changes (see
     * {@see scanWindowEnd()}). When that window holds less than a page of
     * matches, the page ends at the window's edge with a `next_cursor` to
     * keep going, and `scanned_until` says how far back it looked. Every
     * request stays fast on a board with a very long history, and the drawer
     * keeps asking until the page fills or the history ends.
     *
     * @param  array<string, mixed>  $filters
     * @return array{entries: array<int, array<string, mixed>>, next_cursor: string|null, scanned_until: CarbonInterface|null}
     */
    public function page(WorkspaceNavigationItem $board, array $filters, ?string $cursor, int $limit): array
    {
        $position = $this->decodeCursor($cursor);
        $cell_query = $this->cellQuery($board, $filters, $position);
        // Only a filter over the cell changes needs a window, the board events have their own short index.
        $window_end = $cell_query !== null && $this->isFiltered($filters) ? $this->scanWindowEnd($board, $filters, $position) : null;

        $cell_rows = $cell_query?->tap(fn (Builder $query) => $this->applyWindow($query, 'board_item_activities', self::SOURCE_CELL, $window_end))->limit($limit + 1)->get() ?? collect();
        $board_rows = $this->boardQuery($board, $filters, $position)?->tap(fn (Builder $query) => $this->applyWindow($query, 'board_activity_logs', self::SOURCE_BOARD, $window_end))->limit($limit + 1)->get() ?? collect();

        $merged = $cell_rows->map(fn (BoardItemActivity $row) => ['source' => self::SOURCE_CELL, 'row' => $row])
            ->concat($board_rows->map(fn (BoardActivityLog $row) => ['source' => self::SOURCE_BOARD, 'row' => $row]))
            ->sort(fn (array $a, array $b) => $this->compare($b, $a))
            ->values();

        $page = $merged->take($limit);
        $last = $page->last();

        $next_cursor = match (true) {
            $merged->count() > $limit && $last !== null => $this->encodeCursor($last['row']->created_at, $last['source'], $last['row']->id),
            // The window ran out before the page filled, the next request scans the next window.
            $window_end !== null => $this->encodeCursor($window_end['at'], self::SOURCE_CELL, $window_end['id']),
            default => null,
        };

        return [
            'entries' => $this->present($board, $page),
            'next_cursor' => $next_cursor,
            'scanned_until' => $merged->count() > $limit ? null : $window_end['at'] ?? null,
        ];
    }

    /**
     * Whether a filter that can not be answered from an index alone is active
     * (search, columns, groups, event types, people, automations). The plain
     * log and a date range read straight from the index and need no window.
     *
     * @param  array<string, mixed>  $filters
     */
    private function isFiltered(array $filters): bool
    {
        return ($filters['search'] ?? '') !== ''
            || ! empty($filters['column_ids'])
            || ! empty($filters['group_ids'])
            || ! empty($filters['event_types'])
            || ! empty($filters['user_ids'])
            || ! empty($filters['automation_only']);
    }

    /**
     * The oldest of the next `$scan_window` cell changes after the cursor,
     * or null when fewer than that are left (the scan then runs to the end).
     * Read from the `(board_id, created_at, id)` index only, so finding the
     * edge costs a short index walk, not a read of the rows.
     *
     * @param  array<string, mixed>  $filters
     * @param  array{at: CarbonInterface, source: string, id: int}|null  $position
     * @return array{at: CarbonInterface, id: int}|null
     */
    private function scanWindowEnd(WorkspaceNavigationItem $board, array $filters, ?array $position): ?array
    {
        $query = BoardItemActivity::query()->where('board_item_activities.board_id', $board->id);
        $this->applyDates($query, 'board_item_activities.created_at', $filters);
        $this->applyCursor($query, 'board_item_activities', self::SOURCE_CELL, $position);

        $edge = $query->orderByDesc('board_item_activities.created_at')
            ->orderByDesc('board_item_activities.id')
            ->offset($this->scan_window - 1)
            ->limit(1)
            ->toBase()
            ->first(['board_item_activities.id', 'board_item_activities.created_at']);

        return $edge === null ? null : ['at' => Carbon::parse($edge->created_at), 'id' => (int) $edge->id];
    }

    /**
     * Keeps only the rows that sit at or before the window's edge in the merged
     * order (time desc, source rank desc, id desc). The edge row itself is
     * included, the next window starts right after it.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array{at: CarbonInterface, id: int}|null  $window_end
     */
    private function applyWindow(Builder $query, string $table, string $source, ?array $window_end): void
    {
        if ($window_end === null) {
            return;
        }

        if ($source === self::SOURCE_BOARD) {
            // A board event of the same second sorts after the cell edge, so it belongs to the next window.
            $query->where("{$table}.created_at", '>', $window_end['at']);

            return;
        }

        $query->where(function (Builder $query) use ($table, $window_end) {
            $query->where("{$table}.created_at", '>', $window_end['at'])
                ->orWhere(fn (Builder $query) => $query->where("{$table}.created_at", $window_end['at'])->where("{$table}.id", '>=', $window_end['id']));
        });
    }

    /**
     * What the "Filter log" and "Person" menus offer: the board's columns and
     * groups, and everyone who appears in the log.
     *
     * @return array{columns: array<int, array<string, mixed>>, groups: array<int, array<string, mixed>>, people: array<int, array<string, mixed>>}
     */
    public function facets(WorkspaceNavigationItem $board): array
    {
        $columns = BoardColumn::where('board_id', $board->id)
            ->orderBy('board_view_id')
            ->orderBy('scope')
            ->orderBy('position')
            ->get(['id', 'label', 'type', 'scope'])
            ->map(fn (BoardColumn $column) => ['id' => $column->id, 'label' => $column->label, 'type' => $column->type, 'scope' => $column->scope]);

        $groups = BoardGroup::where('board_id', $board->id)
            ->orderBy('board_view_id')
            ->orderBy('position')
            ->get(['id', 'name', 'accent_color', 'is_archived'])
            ->map(fn (BoardGroup $group) => ['id' => $group->id, 'name' => $group->name, 'color' => $group->accent_color, 'is_archived' => (bool) $group->is_archived]);

        // Both read the `(board_id, user_id)` indexes, so they stay quick on a long history.
        $cell_user_ids = BoardItemActivity::where('board_id', $board->id)->whereNotNull('user_id')->distinct()->pluck('user_id');
        $board_user_ids = BoardActivityLog::where('board_id', $board->id)->whereNotNull('user_id')->distinct()->pluck('user_id');

        $people = User::withTrashed()
            ->whereIn('id', $cell_user_ids->concat($board_user_ids)->unique()->values())
            ->get()
            ->sortBy('full_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (User $user) => $this->presentUser($user))
            ->values();

        return ['columns' => $columns->values()->all(), 'groups' => $groups->values()->all(), 'people' => $people->all()];
    }

    /**
     * Cell changes (and renames or group moves) of the board's items, or null
     * when the active event type filter rules every one of them out.
     *
     * @param  array<string, mixed>  $filters
     * @param  array{at: CarbonInterface, source: string, id: int}|null  $position
     * @return Builder<BoardItemActivity>|null
     */
    private function cellQuery(WorkspaceNavigationItem $board, array $filters, ?array $position): ?Builder
    {
        $event_types = $filters['event_types'] ?? [];
        $cell_types = array_values(array_intersect($event_types, self::CELL_EVENT_TYPES));
        if ($event_types !== [] && $cell_types === []) {
            return null;
        }

        // Read from the `(board_id, created_at, id)` index newest first, so MySQL stops after one
        // page instead of sorting the board's whole history. Filters on the item itself (its
        // group, its name) go through a small subquery on the board's items, never a join.
        $query = BoardItemActivity::query()
            ->where('board_item_activities.board_id', $board->id)
            ->with('user');

        if ($cell_types !== []) {
            $query->where(function (Builder $query) use ($cell_types) {
                if (in_array('column_values', $cell_types, true)) {
                    $query->orWhereNotNull('board_item_activities.column_id');
                }
                if (in_array('item_name', $cell_types, true)) {
                    $query->orWhere('board_item_activities.column_type', BoardItemActivityService::FIELD_NAME);
                }
                if (in_array('item_moved', $cell_types, true)) {
                    $query->orWhere('board_item_activities.column_type', BoardItemActivityService::FIELD_GROUP);
                }
            });
        }

        if (! empty($filters['user_ids'])) {
            $query->whereIn('board_item_activities.user_id', $filters['user_ids']);
        }
        if (! empty($filters['automation_only'])) {
            $query->whereNotNull('board_item_activities.automation_id');
        }
        if (! empty($filters['column_ids'])) {
            $query->whereIn('board_item_activities.column_id', $filters['column_ids']);
        }
        if (! empty($filters['group_ids'])) {
            $query->whereIn('board_item_activities.item_id', $this->boardItemIds($board)->whereIn('group_id', $filters['group_ids'])->pluck('id'));
        }
        if (($filters['search'] ?? '') !== '') {
            $like = '%'.$this->escapeLike($filters['search']).'%';
            $query->where(function (Builder $query) use ($board, $like) {
                $query->whereIn('board_item_activities.item_id', $this->boardItemIds($board)->where('name', 'like', $like)->limit(self::MAX_SEARCH_ITEMS)->pluck('id'))
                    ->orWhere('board_item_activities.column_label', 'like', $like)
                    ->orWhere('board_item_activities.old_display', 'like', $like)
                    ->orWhere('board_item_activities.new_display', 'like', $like);
            });
        }

        $this->applyDates($query, 'board_item_activities.created_at', $filters);
        $this->applyCursor($query, 'board_item_activities', self::SOURCE_CELL, $position);

        return $query->orderByDesc('board_item_activities.created_at')->orderByDesc('board_item_activities.id');
    }

    /**
     * The board's items (trashed ones too, their history still shows), to
     * narrow the cell changes by something about the item. The ids are read
     * once and passed as a list: as a subquery MySQL would run it again for
     * every change it scans.
     *
     * @return Builder<BoardItem>
     */
    private function boardItemIds(WorkspaceNavigationItem $board): Builder
    {
        return BoardItem::withTrashed()->where('board_id', $board->id)->select('id');
    }

    /**
     * Structural events of the board, or null when the active filters rule
     * every one of them out.
     *
     * @param  array<string, mixed>  $filters
     * @param  array{at: CarbonInterface, source: string, id: int}|null  $position
     * @return Builder<BoardActivityLog>|null
     */
    private function boardQuery(WorkspaceNavigationItem $board, array $filters, ?array $position): ?Builder
    {
        $event_types = $filters['event_types'] ?? [];
        $actions = collect($event_types)->flatMap(fn (string $type) => self::BOARD_EVENT_TYPES[$type] ?? [])->unique()->values()->all();
        if ($event_types !== [] && $actions === []) {
            return null;
        }

        $query = BoardActivityLog::query()->where('board_id', $board->id)->with('user');

        if ($actions !== []) {
            $query->whereIn('action', $actions);
        }
        if (! empty($filters['user_ids'])) {
            $query->whereIn('user_id', $filters['user_ids']);
        }
        if (! empty($filters['automation_only'])) {
            $query->whereIn('action', [BoardActivityLog::ACTION_AUTOMATION_RAN, BoardActivityLog::ACTION_ITEM_RECURRED]);
        }
        // A column filter keeps only the events about those columns, a group filter the events inside those groups.
        if (! empty($filters['column_ids'])) {
            $query->whereIn('meta->column_id', array_map('intval', $filters['column_ids']));
        }
        if (! empty($filters['group_ids'])) {
            $query->whereIn('meta->group_id', array_map('intval', $filters['group_ids']));
        }
        if (($filters['search'] ?? '') !== '') {
            $query->where('description', 'like', '%'.$this->escapeLike($filters['search']).'%');
        }

        $this->applyDates($query, 'created_at', $filters);
        $this->applyCursor($query, 'board_activity_logs', self::SOURCE_BOARD, $position);

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDates(Builder $query, string $column, array $filters): void
    {
        if (($filters['from'] ?? null) instanceof CarbonInterface) {
            $query->where($column, '>=', $filters['from']);
        }
        if (($filters['to'] ?? null) instanceof CarbonInterface) {
            $query->where($column, '<=', $filters['to']);
        }
    }

    /**
     * Keeps only the rows that come after the cursor in the merged order
     * (time desc, source rank desc, id desc).
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array{at: CarbonInterface, source: string, id: int}|null  $position
     */
    private function applyCursor(Builder $query, string $table, string $source, ?array $position): void
    {
        if ($position === null) {
            return;
        }

        $rank = self::SOURCE_RANK[$source];
        $cursor_rank = self::SOURCE_RANK[$position['source']];

        if ($rank < $cursor_rank) {
            $query->where("{$table}.created_at", '<=', $position['at']);
        } elseif ($rank > $cursor_rank) {
            $query->where("{$table}.created_at", '<', $position['at']);
        } else {
            $query->where(function (Builder $query) use ($table, $position) {
                $query->where("{$table}.created_at", '<', $position['at'])
                    ->orWhere(fn (Builder $query) => $query->where("{$table}.created_at", $position['at'])->where("{$table}.id", '<', $position['id']));
            });
        }
    }

    /**
     * @param  array{source: string, row: BoardItemActivity|BoardActivityLog}  $a
     * @param  array{source: string, row: BoardItemActivity|BoardActivityLog}  $b
     */
    private function compare(array $a, array $b): int
    {
        return [$a['row']->created_at->getTimestamp(), self::SOURCE_RANK[$a['source']], $a['row']->id]
            <=> [$b['row']->created_at->getTimestamp(), self::SOURCE_RANK[$b['source']], $b['row']->id];
    }

    private function encodeCursor(CarbonInterface $at, string $source, int $id): string
    {
        return rtrim(strtr(base64_encode("{$at->getTimestamp()}|{$source}|{$id}"), '+/', '-_'), '=');
    }

    /**
     * @return array{at: CarbonInterface, source: string, id: int}|null
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $parts = explode('|', (string) base64_decode(strtr($cursor, '-_', '+/'), true));
        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || ! isset(self::SOURCE_RANK[$parts[1]]) || ! ctype_digit($parts[2])) {
            return null;
        }

        return ['at' => Carbon::createFromTimestamp((int) $parts[0], config('app.timezone')), 'source' => $parts[1], 'id' => (int) $parts[2]];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Turns a page of raw rows into the shape the Activity log drawer draws.
     * Everything an entry points at (items, groups, columns, people) is
     * loaded once for the whole page.
     *
     * @param  Collection<int, array{source: string, row: BoardItemActivity|BoardActivityLog}>  $page
     * @return array<int, array<string, mixed>>
     */
    public function present(WorkspaceNavigationItem $board, Collection $page): array
    {
        $item_ids = $page->map(fn (array $entry) => $entry['source'] === self::SOURCE_CELL
            ? $entry['row']->item_id
            : ($entry['row']->meta['item_id'] ?? null))->filter()->unique()->values();

        $items = BoardItem::withTrashed()->whereIn('id', $item_ids)->where('board_id', $board->id)
            ->get(['id', 'name', 'group_id', 'parent_id', 'is_archived', 'deleted_at'])->keyBy('id');
        $groups = BoardGroup::where('board_id', $board->id)->get(['id', 'name', 'accent_color', 'is_archived', 'board_view_id'])->keyBy('id');

        $column_ids = $page->map(fn (array $entry) => $entry['source'] === self::SOURCE_CELL
            ? $entry['row']->column_id
            : ($entry['row']->meta['column_id'] ?? null))->filter()->unique()->values();
        $columns = BoardColumn::whereIn('id', $column_ids)->where('board_id', $board->id)->get(['id', 'label', 'type', 'config'])->keyBy('id');

        $people = $this->peopleFor($page);

        return $page->map(fn (array $entry) => $entry['source'] === self::SOURCE_CELL
            ? $this->presentCell($entry['row'], $items, $groups, $columns, $people)
            : $this->presentBoardEvent($board, $entry['row'], $items, $groups, $columns))->values()->all();
    }

    /**
     * @param  Collection<int, BoardItem>  $items
     * @param  Collection<int, BoardGroup>  $groups
     * @param  Collection<int, BoardColumn>  $columns
     * @param  Collection<int, User>  $people
     * @return array<string, mixed>
     */
    private function presentCell(BoardItemActivity $row, Collection $items, Collection $groups, Collection $columns, Collection $people): array
    {
        $item = $items->get($row->item_id);
        $column = $row->column_id !== null ? $columns->get($row->column_id) : null;
        $is_group_move = $row->column_type === BoardItemActivityService::FIELD_GROUP;

        $can_undo = $row->is_undoable
            && $row->undone_at === null
            && $item !== null
            && $item->deleted_at === null
            && ($row->column_id === null || $column !== null)
            && (! $is_group_move || $this->groupAcceptsUndo($groups->get((int) $row->old_value), $groups->get((int) $item->group_id)));

        return [
            'key' => self::SOURCE_CELL.'-'.$row->id,
            'source' => self::SOURCE_CELL,
            'id' => $row->id,
            'action' => match ($row->column_type) {
                BoardItemActivityService::FIELD_NAME => 'item_renamed',
                BoardItemActivityService::FIELD_GROUP => 'item_group_changed',
                default => 'value_changed',
            },
            'created_at' => $row->created_at,
            'user' => $this->presentUser($row->user),
            'automation' => $row->automation_name !== null ? ['id' => $row->automation_id, 'name' => $row->automation_name] : null,
            'item' => $this->presentItem($item, $groups),
            'group' => null,
            'column' => [
                'id' => $row->column_id,
                'label' => $column?->label ?? $row->column_label,
                'type' => $row->column_type,
            ],
            'old_value' => $this->presentValue($row, $row->old_display, $row->old_value, $column, $groups, $people),
            'new_value' => $this->presentValue($row, $row->new_display, $row->new_value, $column, $groups, $people),
            'description' => null,
            'can_undo' => $can_undo,
            'undone_at' => $row->undone_at,
        ];
    }

    /**
     * @param  Collection<int, BoardItem>  $items
     * @param  Collection<int, BoardGroup>  $groups
     * @param  Collection<int, BoardColumn>  $columns
     * @return array<string, mixed>
     */
    private function presentBoardEvent(WorkspaceNavigationItem $board, BoardActivityLog $row, Collection $items, Collection $groups, Collection $columns): array
    {
        $meta = $row->meta ?? [];
        $item = isset($meta['item_id']) ? $items->get((int) $meta['item_id']) : null;
        $group = isset($meta['group_id']) ? $groups->get((int) $meta['group_id']) : null;
        $column = isset($meta['column_id']) ? $columns->get((int) $meta['column_id']) : null;
        $is_rename = in_array($row->action, [BoardActivityLog::ACTION_GROUP_RENAMED, BoardActivityLog::ACTION_COLUMN_RENAMED], true);

        $item_payload = $this->presentItem($item, $groups);
        if ($item_payload === null && isset($meta['item_id'])) {
            // The item was deleted for good, the name kept in `meta` still tells what it was.
            $item_payload = [
                'id' => (int) $meta['item_id'],
                'name' => (string) ($meta['item_name'] ?? 'Deleted item'),
                'parent_id' => $meta['parent_id'] ?? null,
                'is_archived' => false,
                'is_deleted' => true,
                'group' => isset($meta['group_name']) ? ['id' => $meta['group_id'] ?? null, 'name' => $meta['group_name'], 'color' => $meta['group_color'] ?? null] : null,
            ];
        }

        $group_payload = isset($meta['group_id']) || isset($meta['group_name']) ? [
            'id' => $meta['group_id'] ?? null,
            'name' => $group?->name ?? ($meta['group_name'] ?? null),
            'color' => $group?->accent_color ?? ($meta['group_color'] ?? null),
        ] : null;

        return [
            'key' => self::SOURCE_BOARD.'-'.$row->id,
            'source' => self::SOURCE_BOARD,
            'id' => $row->id,
            'action' => $row->action,
            'created_at' => $row->created_at,
            'user' => $this->presentUser($row->user),
            'automation' => $row->action === BoardActivityLog::ACTION_AUTOMATION_RAN ? ['id' => $meta['automation_id'] ?? null, 'name' => (string) ($meta['automation_name'] ?? 'Automation')] : null,
            'item' => $item_payload,
            'group' => $group_payload,
            'column' => isset($meta['column_id']) ? [
                'id' => (int) $meta['column_id'],
                'label' => $column?->label ?? ($meta['column_label'] ?? 'Column'),
                'type' => $column?->type ?? ($meta['column_type'] ?? null),
            ] : null,
            'board_name' => $board->label,
            'old_value' => $is_rename && isset($meta['old_name']) ? ['text' => (string) $meta['old_name']] : null,
            'new_value' => $is_rename && isset($meta['new_name']) ? ['text' => (string) $meta['new_name']] : null,
            'description' => $row->description,
            'can_undo' => $row->undone_at === null && $this->boardEventCanBeUndone($row, $item, $group, $column),
            'undone_at' => $row->undone_at,
        ];
    }

    private function boardEventCanBeUndone(BoardActivityLog $row, ?BoardItem $item, ?BoardGroup $group, ?BoardColumn $column): bool
    {
        return match ($row->action) {
            BoardActivityLog::ACTION_ITEM_CREATED => $item !== null && $item->deleted_at === null,
            BoardActivityLog::ACTION_ITEM_ARCHIVED => $item !== null && $item->deleted_at === null && $item->is_archived,
            BoardActivityLog::ACTION_ITEM_TRASHED => $item !== null && $item->deleted_at !== null,
            BoardActivityLog::ACTION_GROUP_ARCHIVED => $group !== null && $group->is_archived,
            BoardActivityLog::ACTION_GROUP_RENAMED => $group !== null && isset($row->meta['old_name']),
            BoardActivityLog::ACTION_COLUMN_RENAMED => $column !== null && isset($row->meta['old_name']),
            default => false,
        };
    }

    /**
     * A group move can be undone while the old group still exists, is not
     * archived and sits on the same tab as the item's current group.
     */
    public function groupAcceptsUndo(?BoardGroup $old_group, ?BoardGroup $current_group): bool
    {
        return $old_group !== null
            && ! $old_group->is_archived
            && ($current_group === null || $current_group->board_view_id === $old_group->board_view_id);
    }

    /**
     * @param  Collection<int, BoardGroup>  $groups
     * @return array<string, mixed>|null
     */
    private function presentItem(?BoardItem $item, Collection $groups): ?array
    {
        if ($item === null) {
            return null;
        }

        $group = $groups->get($item->group_id);

        return [
            'id' => $item->id,
            'name' => $item->name,
            'parent_id' => $item->parent_id,
            'is_archived' => (bool) $item->is_archived,
            'is_deleted' => $item->deleted_at !== null,
            'group' => $group !== null ? ['id' => $group->id, 'name' => $group->name, 'color' => $group->accent_color] : null,
        ];
    }

    /**
     * One side of a change: its text, plus a label color for a status or
     * label column (or a group move) and the people of a People column.
     *
     * @param  Collection<int, BoardGroup>  $groups
     * @param  Collection<int, User>  $people
     * @return array<string, mixed>|null
     */
    private function presentValue(BoardItemActivity $row, ?string $display, mixed $raw, ?BoardColumn $column, Collection $groups, Collection $people): ?array
    {
        if ($display === null && $raw === null) {
            return null;
        }

        $value = ['text' => $display];

        if ($row->column_type === BoardItemActivityService::FIELD_GROUP) {
            $group = $raw !== null ? $groups->get((int) $raw) : $groups->firstWhere('name', $display);
            $value['color'] = $group?->accent_color;
        } elseif ($column !== null && in_array($column->type, self::LABEL_COLUMN_TYPES, true)) {
            $options = collect($column->config['options'] ?? []);
            $option = $raw !== null && is_scalar($raw)
                ? $options->first(fn ($option) => (string) ($option['id'] ?? '') === (string) $raw)
                : null;
            $option ??= $options->first(fn ($option) => ($option['label'] ?? null) === $display);
            $value['color'] = $option['color'] ?? '#c4c4c4';
        } elseif ($row->column_type === BoardColumn::TYPE_PEOPLE && is_array($raw)) {
            $value['people'] = collect($raw)
                ->map(fn ($id) => $people->get((int) $id))
                ->filter()
                ->map(fn (User $user) => $this->presentUser($user))
                ->values()
                ->all();
        }

        return $value;
    }

    /**
     * Everyone named by a People column change on the page, in one query.
     *
     * @param  Collection<int, array{source: string, row: BoardItemActivity|BoardActivityLog}>  $page
     * @return Collection<int, User>
     */
    private function peopleFor(Collection $page): Collection
    {
        $user_ids = $page
            ->filter(fn (array $entry) => $entry['source'] === self::SOURCE_CELL && $entry['row']->column_type === BoardColumn::TYPE_PEOPLE)
            ->flatMap(fn (array $entry) => [...(array) ($entry['row']->old_value ?? []), ...(array) ($entry['row']->new_value ?? [])])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $user_ids->isEmpty() ? collect() : User::withTrashed()->whereIn('id', $user_ids)->get()->keyBy('id');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentUser(?User $user): ?array
    {
        return $user !== null ? [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'profile_photo_url' => $user->profile_photo_url,
            'is_deactivated' => $user->is_deactivated,
        ] : null;
    }
}
