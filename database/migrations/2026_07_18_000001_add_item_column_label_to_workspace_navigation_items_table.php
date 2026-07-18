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
            // The label shown on the board's first (item name) column, which — unlike
            // the other columns — isn't a `board_columns` row, so its custom name lives
            // on the board itself. Null falls back to the default "Item" on the frontend.
            $table->string('item_column_label')->nullable()->after('board_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_navigation_items', function (Blueprint $table) {
            $table->dropColumn('item_column_label');
        });
    }
};
