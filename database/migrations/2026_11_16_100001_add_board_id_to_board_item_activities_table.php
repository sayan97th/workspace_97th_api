<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Rows backfilled per UPDATE, keeps each statement short on a big table. */
    private const BACKFILL_CHUNK = 20000;

    /**
     * Run the migrations.
     *
     * The board Activity log reads a board's cell changes newest first. Going
     * through `board_items` to find them made MySQL gather and sort the whole
     * history of the board on every page (`Using temporary; Using filesort`).
     * With `board_id` on the row and a `(board_id, created_at, id)` index it
     * reads the newest rows straight from the index and stops after one page,
     * however long the history is. `(board_id, user_id)` serves the Person
     * filter choices the same way. `board_visits` gets the index its "Last
     * viewed" list needs.
     */
    public function up(): void
    {
        Schema::table('board_item_activities', function (Blueprint $table) {
            $table->unsignedBigInteger('board_id')->nullable()->after('item_id');
        });

        $max_id = (int) DB::table('board_item_activities')->max('id');
        // A correlated subquery rather than UPDATE ... JOIN, so it also runs on SQLite (the test database).
        for ($from = 0; $from < $max_id; $from += self::BACKFILL_CHUNK) {
            DB::table('board_item_activities')
                ->where('id', '>', $from)
                ->where('id', '<=', $from + self::BACKFILL_CHUNK)
                ->update(['board_id' => DB::raw('(select board_items.board_id from board_items where board_items.id = board_item_activities.item_id)')]);
        }

        Schema::table('board_item_activities', function (Blueprint $table) {
            $table->index(['board_id', 'created_at', 'id'], 'board_item_activities_board_feed_index');
            $table->index(['board_id', 'user_id'], 'board_item_activities_board_user_index');
        });

        Schema::table('board_activity_logs', function (Blueprint $table) {
            $table->index(['board_id', 'user_id'], 'board_activity_logs_board_user_index');
        });

        Schema::table('board_visits', function (Blueprint $table) {
            $table->index(['board_id', 'visited_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_visits', function (Blueprint $table) {
            $table->dropIndex(['board_id', 'visited_at']);
        });

        Schema::table('board_activity_logs', function (Blueprint $table) {
            $table->dropIndex('board_activity_logs_board_user_index');
        });

        Schema::table('board_item_activities', function (Blueprint $table) {
            $table->dropIndex('board_item_activities_board_feed_index');
            $table->dropIndex('board_item_activities_board_user_index');
            $table->dropColumn('board_id');
        });
    }
};
