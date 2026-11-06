<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use Illuminate\Support\Collection;

/**
 * Writes the item's creator into People columns on the server, the way a
 * "Reporter" column works: it reads and edits like any People column, it
 * just starts out with the person who created the item.
 *
 * - A People column with `config.auto_assign_creator` gets the item's creator
 *   the moment a new item lands on its tab (see {@see fillItem()}).
 * - "Fill empty cells", a new Reporter column and a Creation log column
 *   changed into a People column write each item's creator into the cells
 *   that are still empty (see {@see fillEmptyCells()}).
 *
 * A cell that already holds people is never overwritten, so a person someone
 * picked, or one a client sent along with the new item, always wins. Items
 * without a known creator (imported rows, some automations) are left empty.
 *
 * Values are written as `["<user id>"]`, the same shape the People cell's
 * picker stores, and no "Assigned you" notification is sent, since the
 * creator is the one who made the item.
 */
class BoardPeopleAutoAssigner
{
    /**
     * Assigns the item's creator in every auto assign People column of the
     * tab and scope the item lives in, leaving cells that already hold people.
     */
    public function fillItem(BoardItem $board_item, int $board_view_id, string $scope): void
    {
        if ($board_item->created_by_id === null) {
            return;
        }

        $columns = $this->autoAssignColumns($board_view_id, $scope);
        if ($columns->isEmpty()) {
            return;
        }

        $filled_column_ids = BoardItemValue::where('item_id', $board_item->id)
            ->whereIn('column_id', $columns->pluck('id'))
            ->get(['column_id', 'value'])
            ->filter(fn (BoardItemValue $value) => ! self::isEmptyValue($value->value))
            ->pluck('column_id')
            ->all();

        foreach ($columns as $column) {
            if (in_array($column->id, $filled_column_ids, true)) {
                continue;
            }

            BoardItemValue::updateOrCreate(
                ['item_id' => $board_item->id, 'column_id' => $column->id],
                ['value' => self::valueFor($board_item->created_by_id)],
            );
        }
    }

    /**
     * The tab's People columns of `$scope` that assign each new item's creator.
     *
     * @return Collection<int, BoardColumn>
     */
    public function autoAssignColumns(int $board_view_id, string $scope): Collection
    {
        return BoardColumn::where('board_view_id', $board_view_id)
            ->where('scope', $scope)
            ->where('type', BoardColumn::TYPE_PEOPLE)
            ->get(['id', 'type', 'config'])
            ->filter(fn (BoardColumn $column) => $column->autoAssignsCreator())
            ->values();
    }

    /**
     * Writes each item's creator into the column's empty cells, subitems
     * included for a subitem column, and returns what it wrote keyed by item
     * id, so the caller can patch its rows in place.
     *
     * @return array<int, array<int, string>>
     */
    public function fillEmptyCells(BoardColumn $column): array
    {
        if ($column->type !== BoardColumn::TYPE_PEOPLE) {
            return [];
        }

        $group_ids = BoardGroup::where('board_view_id', $column->board_view_id)->pluck('id');
        $filled = [];

        BoardItem::query()
            ->whereIn('group_id', $group_ids)
            ->whereNotNull('created_by_id')
            ->when(
                $column->scope === BoardColumn::SCOPE_SUBITEM,
                fn ($query) => $query->whereNotNull('parent_id'),
                fn ($query) => $query->whereNull('parent_id'),
            )
            ->with(['values' => fn ($query) => $query->where('column_id', $column->id)])
            ->select(['id', 'created_by_id'])
            ->chunkById(500, function ($board_items) use ($column, &$filled) {
                foreach ($board_items as $board_item) {
                    $current = $board_item->values->first();
                    if ($current !== null && ! self::isEmptyValue($current->value)) {
                        continue;
                    }

                    $value = self::valueFor($board_item->created_by_id);
                    BoardItemValue::updateOrCreate(
                        ['item_id' => $board_item->id, 'column_id' => $column->id],
                        ['value' => $value],
                    );
                    $filled[$board_item->id] = $value;
                }
            });

        return $filled;
    }

    /**
     * Whether a stored People cell holds nobody, the only cells this class ever
     * writes to. Anything that isn't a list of ids counts as empty too, like
     * the dates a Creation log column kept from when it was a Date column.
     */
    public static function isEmptyValue(mixed $value): bool
    {
        if (! is_array($value)) {
            return true;
        }

        return array_filter($value, fn ($id) => is_numeric($id)) === [];
    }

    /**
     * @return array<int, string>
     */
    private static function valueFor(int $user_id): array
    {
        return [(string) $user_id];
    }
}
