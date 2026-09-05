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
            // The width of the board's first (item name) column, which — like `item_column_label` —
            // isn't a `board_columns` row, so its custom size lives on the board itself. Null falls
            // back to the frontend's auto-sized default (based on the longest item name).
            $table->unsignedSmallInteger('item_column_width')->nullable()->after('item_column_label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_navigation_items', function (Blueprint $table) {
            $table->dropColumn('item_column_width');
        });
    }
};
