<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\BoardView;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Support\BoardEditGate;
use App\Support\VisibleBoards;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Connect boards column, monday.com style. A column links each item to items on one or more
 * other boards (`config.linked_board_ids`, the first one is also kept as `config.linked_board_id`
 * for automations and mirrors written before several boards were allowed). Its cell value is the
 * list of linked item ids.
 *
 * `config.allow_multiple_items` (default true) says whether a cell may hold more than one item.
 * With `config.two_way` the connected board gets a column of its own (`config.reverse_column_id`
 * on both sides) that always shows the links from the other direction, kept in step by
 * {@see syncReverseLinks()}.
 */
class ConnectBoardColumnService
{
    /** The most boards one column may connect, like monday.com. */
    public const MAX_LINKED_BOARDS = 10;

    /** Items sent per connected board, enough for the picker without loading a huge board whole. */
    private const MAX_ITEMS_PER_BOARD = 3000;

    /** Longest searchable text kept per cell, the picker only matches on it. */
    private const MAX_SEARCH_TEXT_LENGTH = 200;

    /** Column types whose values have no meaningful text to search on. */
    private const UNSEARCHABLE_TYPES = [
        BoardColumn::TYPE_FILES,
        BoardColumn::TYPE_BUTTON,
        BoardColumn::TYPE_FORMULA,
        BoardColumn::TYPE_MIRROR,
        BoardColumn::TYPE_CONNECT_BOARD,
        BoardColumn::TYPE_DEPENDENCY,
        BoardColumn::TYPE_CHECKLIST,
        BoardColumn::TYPE_TIME_TRACKING,
        BoardColumn::TYPE_VOTE,
    ];

    /**
     * The connected boards, in the order the column lists them.
     *
     * @return array<int, int>
     */
    public static function linkedBoardIds(BoardColumn $column): array
    {
        $config = $column->config ?? [];
        $ids = $config['linked_board_ids'] ?? (isset($config['linked_board_id']) ? [$config['linked_board_id']] : []);

        return array_values(array_unique(array_map('intval', array_filter((array) $ids, 'is_numeric'))));
    }

    /**
     * A cell value as a list of item ids, whatever shape it was stored in.
     *
     * @return array<int, string>
     */
    public static function itemIds(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        return array_values(array_unique(array_map('strval', array_filter((array) $value, 'is_numeric'))));
    }

    /**
     * Everything the cell picker needs for each connected board: its groups (with their
     * colors), its columns (for "Choose columns to search"), and its items with the searchable
     * text of each of their cells. Items this column already links to are always included,
     * even from another tab, so their names show in the cells. Like monday.com, only users who
     * can open every connected board see the column's contents, everyone else gets no boards
     * and the count of the ones they cannot open.
     *
     * @return array{boards: array<int, array<string, mixed>>, hidden_board_count: int}
     */
    public function options(BoardColumn $column, User $user): array
    {
        $board_ids = self::linkedBoardIds($column);
        if ($board_ids === []) {
            return ['boards' => [], 'hidden_board_count' => 0];
        }

        $boards = VisibleBoards::query($user)->whereIn('id', $board_ids)->get()->keyBy('id');
        $hidden_board_count = count($board_ids) - $boards->count();
        if ($hidden_board_count > 0) {
            return ['boards' => [], 'hidden_board_count' => $hidden_board_count];
        }

        $linked_item_ids = BoardItemValue::where('column_id', $column->id)
            ->pluck('value')
            ->flatMap(fn ($value) => self::itemIds($value))
            ->unique()
            ->values()
            ->all();

        $mirrored_column_ids = $this->mirroredColumnIds($column);

        $payload = collect($board_ids)
            ->map(fn (int $board_id) => $this->boardOptions($boards->get($board_id), $user, $linked_item_ids, $mirrored_column_ids))
            ->all();

        return ['boards' => $payload, 'hidden_board_count' => 0];
    }

    /**
     * The connected boards as the "Connected board column settings" lists them: name, whether
     * the board is private, and where it lives ("Workspace > Folder"). A board the user may not
     * open is listed without its name or path. Deleted boards are left out.
     *
     * @return array<int, array{id: int, name: string|null, is_private: bool, is_accessible: bool, path: array<int, string>}>
     */
    public function connectedBoards(BoardColumn $column, User $user): array
    {
        $board_ids = self::linkedBoardIds($column);
        $boards = WorkspaceNavigationItem::with('workspace:id,name')->whereIn('id', $board_ids)->get()->keyBy('id');
        $visible_ids = VisibleBoards::query($user)->whereIn('id', $board_ids)->pluck('id')->flip();

        return collect($board_ids)
            ->map(fn (int $board_id) => $boards->get($board_id))
            ->filter()
            ->map(function (WorkspaceNavigationItem $board) use ($visible_ids) {
                $is_accessible = $visible_ids->has($board->id);

                return [
                    'id' => $board->id,
                    'name' => $is_accessible ? $board->label : null,
                    'is_private' => $board->board_type === WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
                    'is_accessible' => $is_accessible,
                    'path' => $is_accessible
                        ? array_values(array_filter([$board->workspace?->name, ...$board->ancestors()->pluck('label')->all()]))
                        : [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Saves the column's connection settings, creating or detaching the column on the
     * connected board that a two-way connection keeps in step.
     *
     * @param  array{linked_board_ids: array<int, int>, allow_multiple_items?: bool, two_way?: bool}  $settings
     */
    public function updateSettings(WorkspaceNavigationItem $board, BoardColumn $column, array $settings, User $user): BoardColumn
    {
        // Boards deleted since they were connected are dropped. Disconnecting the last board leaves the column unconnected.
        $requested_ids = array_values(array_unique(array_map('intval', $settings['linked_board_ids'])));
        $existing_ids = WorkspaceNavigationItem::whereIn('id', $requested_ids)->pluck('id')->all();
        $board_ids = array_values(array_filter($requested_ids, fn (int $id) => in_array($id, $existing_ids, true)));

        $config = $column->config ?? [];
        $previous_reverse_id = isset($config['reverse_column_id']) ? (int) $config['reverse_column_id'] : null;

        $config['linked_board_ids'] = $board_ids;
        if ($board_ids === []) {
            unset($config['linked_board_id']);
        } else {
            $config['linked_board_id'] = $board_ids[0];
        }
        $config['allow_multiple_items'] = (bool) ($settings['allow_multiple_items'] ?? true);

        // Two-way only works with a single connected board, like monday.com.
        $wants_two_way = (bool) ($settings['two_way'] ?? false) && count($board_ids) === 1;

        return DB::transaction(function () use ($board, $column, $config, $board_ids, $previous_reverse_id, $wants_two_way, $user) {
            $previous_reverse = $previous_reverse_id ? BoardColumn::where('type', BoardColumn::TYPE_CONNECT_BOARD)->find($previous_reverse_id) : null;
            $keeps_reverse = $wants_two_way && $previous_reverse && $previous_reverse->board_id === $board_ids[0];

            if ($previous_reverse && ! $keeps_reverse) {
                $this->detachReverse($previous_reverse);
            }

            if ($wants_two_way) {
                $reverse = $keeps_reverse ? $previous_reverse : $this->createReverseColumn($board, $column, $board_ids[0], $user);
                $config['two_way'] = true;
                $config['reverse_column_id'] = $reverse->id;
            } else {
                $config['two_way'] = false;
                unset($config['reverse_column_id']);
            }

            $column->forceFill(['config' => $config])->save();

            if ($wants_two_way && ! $keeps_reverse) {
                $this->backfillReverse($column, $config['reverse_column_id']);
            }

            return $column->fresh();
        });
    }

    /**
     * After `$item`'s cell in `$column` changed from `$old_value` to `$new_value`, adds `$item`
     * to (or removes it from) the matching cell of every item it was linked to or unlinked from,
     * in the two-way column on the connected board. Written straight to the values, so it never
     * loops back into this sync.
     */
    public function syncReverseLinks(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value): void
    {
        $reverse_id = $column->config['reverse_column_id'] ?? null;
        if ($column->type !== BoardColumn::TYPE_CONNECT_BOARD || empty($column->config['two_way']) || ! $reverse_id) {
            return;
        }

        $reverse = BoardColumn::where('type', BoardColumn::TYPE_CONNECT_BOARD)->find((int) $reverse_id);
        if (! $reverse) {
            return;
        }

        $old_ids = self::itemIds($old_value);
        $new_ids = self::itemIds($new_value);
        $added = array_diff($new_ids, $old_ids);
        $removed = array_diff($old_ids, $new_ids);
        if ($added === [] && $removed === []) {
            return;
        }

        $targets = BoardItem::where('board_id', $reverse->board_id)->whereIn('id', array_merge($added, $removed))->pluck('id');
        $item_id = (string) $item->id;

        foreach ($targets as $target_id) {
            $is_added = in_array((string) $target_id, $added, true);
            $this->updateLinkedIds($target_id, $reverse->id, fn (array $ids) => $is_added
                ? array_values(array_unique([...$ids, $item_id]))
                : array_values(array_diff($ids, [$item_id])));
        }
    }

    /**
     * The connected boards' columns shown by this board's Mirror columns that read through
     * `$column`. Their raw values travel with each item, so a mirror cell can draw them like
     * the original column (a status bar, a pill) and edit them.
     *
     * @return array<int, int>
     */
    private function mirroredColumnIds(BoardColumn $column): array
    {
        return BoardColumn::where('board_id', $column->board_id)
            ->where('type', BoardColumn::TYPE_MIRROR)
            ->get(['id', 'config'])
            ->filter(fn (BoardColumn $mirror) => (int) ($mirror->config['source_column_id'] ?? 0) === $column->id && isset($mirror->config['mirrored_column_id']))
            ->map(fn (BoardColumn $mirror) => (int) $mirror->config['mirrored_column_id'])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $linked_item_ids
     * @param  array<int, int>  $mirrored_column_ids
     * @return array<string, mixed>
     */
    private function boardOptions(WorkspaceNavigationItem $board, User $user, array $linked_item_ids, array $mirrored_column_ids = []): array
    {
        $view = $this->primaryView($board);

        $groups = $view
            ? BoardGroup::where('board_view_id', $view->id)->where('is_archived', false)->orderBy('position')->get(['id', 'name', 'accent_color', 'board_view_id'])
            : collect();

        $columns = $view
            ? BoardColumn::where('board_view_id', $view->id)->where('scope', BoardColumn::SCOPE_ITEM)->orderBy('position')->get(['id', 'label', 'type', 'config'])
            : collect();
        $searchable_columns = $columns->reject(fn (BoardColumn $searchable) => in_array($searchable->type, self::UNSEARCHABLE_TYPES, true))->keyBy('id');
        $mirrored_columns = $mirrored_column_ids === []
            ? collect()
            : BoardColumn::where('board_id', $board->id)->whereIn('id', $mirrored_column_ids)->get(['id', 'label', 'type', 'config'])->keyBy('id');

        $items = BoardItem::query()
            ->where('board_id', $board->id)
            ->whereNull('parent_id')
            ->where('is_archived', false)
            ->where(fn ($query) => $query->whereIn('group_id', $groups->pluck('id'))->orWhereIn('id', $linked_item_ids))
            ->with([
                'group:id,accent_color,board_view_id',
                'values' => fn ($query) => $query->whereIn('column_id', $searchable_columns->keys()->merge($mirrored_columns->keys())->unique()),
            ])
            ->orderBy('position')
            ->limit(self::MAX_ITEMS_PER_BOARD)
            ->get(['id', 'name', 'group_id', 'created_at', 'position']);

        $texts = new ConnectItemSearchText($board, $searchable_columns, $items);
        $listed_group_ids = $groups->pluck('id')->flip();

        return [
            'id' => $board->id,
            'name' => $board->label,
            'view_id' => $view?->id,
            'can_add_items' => BoardEditGate::allowsContent($board, $user) && $groups->isNotEmpty(),
            'groups' => $groups->map(fn (BoardGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'color' => $group->accent_color,
            ])->values()->all(),
            'columns' => $columns->map(fn (BoardColumn $board_column) => [
                'id' => $board_column->id,
                'label' => $board_column->label,
                'type' => $board_column->type,
                'is_searchable' => $searchable_columns->has($board_column->id),
            ])->values()->all(),
            'items' => $items->map(fn (BoardItem $board_item) => [
                'id' => $board_item->id,
                'name' => $board_item->name,
                // Null when the item lives in another tab, it is shown in cells but not listed in the picker's groups.
                'group_id' => $listed_group_ids->has($board_item->group_id) ? $board_item->group_id : null,
                'color' => $board_item->group?->accent_color,
                'view_id' => $board_item->group?->board_view_id,
                'created_at' => $board_item->created_at?->toISOString(),
                'texts' => $texts->forItem($board_item, self::MAX_SEARCH_TEXT_LENGTH),
                // Raw values of the mirrored columns only, keyed by column id.
                'values' => (object) $board_item->values
                    ->whereIn('column_id', $mirrored_columns->keys())
                    ->mapWithKeys(fn (BoardItemValue $value) => [(string) $value->column_id => $value->value])
                    ->all(),
            ])->values()->all(),
            'mirrored_columns' => $mirrored_columns->values()->map(fn (BoardColumn $mirrored) => [
                'id' => $mirrored->id,
                'label' => $mirrored->label,
                'type' => $mirrored->type,
                'options' => $mirrored->config['options'] ?? [],
            ])->all(),
        ];
    }

    private function primaryView(WorkspaceNavigationItem $board): ?BoardView
    {
        return $board->views()->orderByDesc('is_primary')->orderBy('position')->first();
    }

    /**
     * The column a two-way connection adds to the connected board, named after this board.
     */
    private function createReverseColumn(WorkspaceNavigationItem $board, BoardColumn $column, int $linked_board_id, User $user): BoardColumn
    {
        $linked_board = WorkspaceNavigationItem::findOrFail($linked_board_id);
        if (! BoardEditGate::allowsStructure($linked_board, $user)) {
            throw ValidationException::withMessages([
                'two_way' => "You need permission to add columns on \"{$linked_board->label}\" to connect it both ways.",
            ]);
        }

        $view = app(BoardViewResolver::class)->resolveForWrite($linked_board, null);
        $position = (int) BoardColumn::where('board_view_id', $view->id)->where('scope', BoardColumn::SCOPE_ITEM)->max('position') + 1;

        return $view->columns()->create([
            'board_id' => $linked_board->id,
            'key' => 'connect_'.Str::lower(Str::random(10)),
            'label' => Str::limit($board->label ?: $column->label, 250, ''),
            'type' => BoardColumn::TYPE_CONNECT_BOARD,
            'scope' => BoardColumn::SCOPE_ITEM,
            'position' => $position,
            'width' => 200,
            'config' => [
                'linked_board_ids' => [$board->id],
                'linked_board_id' => $board->id,
                'allow_multiple_items' => true,
                'two_way' => true,
                'reverse_column_id' => $column->id,
            ],
            'hideable' => true,
            'pinnable' => true,
        ]);
    }

    /** Fills a new two-way column with every link this column already holds. */
    private function backfillReverse(BoardColumn $column, int $reverse_id): void
    {
        $reverse = BoardColumn::find($reverse_id);
        if (! $reverse) {
            return;
        }

        $reverse_links = [];
        BoardItemValue::where('column_id', $column->id)->get(['item_id', 'value'])->each(function (BoardItemValue $value) use (&$reverse_links) {
            foreach (self::itemIds($value->value) as $linked_id) {
                $reverse_links[$linked_id][] = (string) $value->item_id;
            }
        });

        $targets = BoardItem::where('board_id', $reverse->board_id)->whereIn('id', array_keys($reverse_links))->pluck('id');
        foreach ($targets as $target_id) {
            $this->updateLinkedIds($target_id, $reverse->id, fn (array $ids) => array_values(array_unique([...$ids, ...$reverse_links[(string) $target_id]])));
        }
    }

    /** Turning two-way off keeps the other board's column and its values, it just stops syncing. */
    private function detachReverse(BoardColumn $reverse): void
    {
        $config = $reverse->config ?? [];
        $config['two_way'] = false;
        unset($config['reverse_column_id']);
        $reverse->forceFill(['config' => $config])->save();
    }

    /**
     * @param  callable(array<int, string>): array<int, string>  $change
     */
    private function updateLinkedIds(int $item_id, int $column_id, callable $change): void
    {
        $value = BoardItemValue::firstOrNew(['item_id' => $item_id, 'column_id' => $column_id]);
        $next = $change(self::itemIds($value->value));

        if ($next === [] && ! $value->exists) {
            return;
        }

        $value->value = $next === [] ? null : $next;
        $value->save();
    }
}
