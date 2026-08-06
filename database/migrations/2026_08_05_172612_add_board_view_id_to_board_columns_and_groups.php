<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Scopes each board's columns and groups (tables) to one specific tab
     * (`board_views` row) instead of the whole board — so switching tabs can
     * show genuinely different content, not just a different filter over the
     * same shared data. `board_items` needs no column of its own: an item's
     * tab is derived transitively through `board_items.group_id ->
     * board_groups.board_view_id`, since every item already requires a group.
     */
    public function up(): void
    {
        Schema::table('board_columns', function (Blueprint $table) {
            $table->foreignId('board_view_id')->nullable()->after('board_id')
                ->constrained('board_views')->cascadeOnDelete();
            $table->dropUnique(['board_id', 'key']);
        });

        Schema::table('board_columns', function (Blueprint $table) {
            // Columns are now independent per tab, so two tabs may reuse the
            // same `key` — uniqueness moves from per-board to per-view.
            $table->unique(['board_view_id', 'key']);
            $table->index(['board_view_id', 'position']);
        });

        Schema::table('board_groups', function (Blueprint $table) {
            $table->foreignId('board_view_id')->nullable()->after('board_id')
                ->constrained('board_views')->cascadeOnDelete();
            $table->index(['board_view_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_columns', function (Blueprint $table) {
            // The foreign key constraint must be dropped before its
            // supporting indexes, or MySQL refuses to drop them (error 1553).
            $table->dropForeign(['board_view_id']);
            $table->dropUnique(['board_view_id', 'key']);
            $table->dropIndex(['board_view_id', 'position']);
            $table->dropColumn('board_view_id');
        });

        Schema::table('board_columns', function (Blueprint $table) {
            $table->unique(['board_id', 'key']);
        });

        Schema::table('board_groups', function (Blueprint $table) {
            $table->dropForeign(['board_view_id']);
            $table->dropIndex(['board_view_id', 'position']);
            $table->dropColumn('board_view_id');
        });
    }
};
