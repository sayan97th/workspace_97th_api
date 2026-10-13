<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;

/**
 * Fills "Item ID" (`auto_number`) columns. Like monday.com, an item's Item ID
 * is the item's own id, so it never changes when the item is moved, and a
 * copy gets its own. It is still written as the cell's value, so sorting,
 * filters, formulas, automations and exports read it like any number.
 */
class BoardItemIdAssigner
{
    /**
     * Writes the item's id into every Item ID column of the tab and scope it
     * lives in, replacing a value carried over from somewhere else.
     */
    public function assignToItem(BoardItem $board_item, int $board_view_id, string $scope): void
    {
        $column_ids = BoardColumn::where('board_view_id', $board_view_id)
            ->where('scope', $scope)
            ->where('type', BoardColumn::TYPE_AUTO_NUMBER)
            ->pluck('id');

        foreach ($column_ids as $column_id) {
            BoardItemValue::updateOrCreate(
                ['item_id' => $board_item->id, 'column_id' => $column_id],
                ['value' => $board_item->id],
            );
        }
    }

    /**
     * Gives every item already on the column's tab its Item ID, for a column
     * that was just added (or turned into an Item ID column).
     */
    public function backfillColumn(BoardColumn $column): void
    {
        if ($column->type !== BoardColumn::TYPE_AUTO_NUMBER) {
            return;
        }

        $group_ids = BoardGroup::where('board_view_id', $column->board_view_id)->pluck('id');

        // Deleted items too, so an undone delete brings the row back with its id.
        BoardItem::withTrashed()
            ->whereIn('group_id', $group_ids)
            ->when(
                $column->scope === BoardColumn::SCOPE_SUBITEM,
                fn ($query) => $query->whereNotNull('parent_id'),
                fn ($query) => $query->whereNull('parent_id'),
            )
            ->select('id')
            ->chunkById(500, function ($board_items) use ($column) {
                foreach ($board_items as $board_item) {
                    BoardItemValue::updateOrCreate(
                        ['item_id' => $board_item->id, 'column_id' => $column->id],
                        ['value' => $board_item->id],
                    );
                }
            });
    }
}
