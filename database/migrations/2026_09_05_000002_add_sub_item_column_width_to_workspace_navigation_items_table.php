<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workspace_navigation_items', function (Blueprint $table) {
            // The width of the Subitems table's own first (subitem name) column — the
            // subitem-tree analogue of `item_column_width`. Same reasoning: it isn't a
            // `board_columns` row, so its custom size lives on the board itself. Null
            // falls back to the frontend's per-item auto-sizing (based on the longest
            // subitem name in that item's own subitem list).
            $table->unsignedSmallInteger('sub_item_column_width')->nullable()->after('item_column_width');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_navigation_items', function (Blueprint $table) {
            $table->dropColumn('sub_item_column_width');
        });
    }
};
