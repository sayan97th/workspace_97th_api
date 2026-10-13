<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Item ID (`auto_number`) columns used to count 1, 2, 3 per column, and
 * items created before the column was added stayed empty. Like monday.com,
 * an Item ID is now the item's own id, so every existing cell is rewritten
 * and the empty ones are filled. Deleted items are included, so an undone
 * delete brings its row back with the right id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = DB::table('board_columns')->where('type', 'auto_number')->get(['id', 'board_view_id', 'scope']);

        foreach ($columns as $column) {
            $group_ids = DB::table('board_groups')->where('board_view_id', $column->board_view_id)->pluck('id');

            DB::table('board_items')
                ->whereIn('group_id', $group_ids)
                ->when(
                    $column->scope === 'subitem',
                    fn ($query) => $query->whereNotNull('parent_id'),
                    fn ($query) => $query->whereNull('parent_id'),
                )
                ->select('id')
                ->orderBy('id')
                ->chunk(500, function ($items) use ($column) {
                    $now = now();
                    $rows = $items->map(fn ($item) => [
                        'item_id' => $item->id,
                        'column_id' => $column->id,
                        'value' => json_encode($item->id),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();

                    DB::table('board_item_values')->upsert($rows, ['item_id', 'column_id'], ['value', 'updated_at']);
                });
        }
    }

    /** The old counters are not kept, so there is nothing to restore. */
    public function down(): void {}
};
