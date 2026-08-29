<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Subitems get their own, independent column set from their parent item —
     * mirroring monday.com, where a board's subitems live on an implicit
     * separate sub-board with their own columns. `scope` ('item' | 'subitem')
     * is a third partitioning key alongside `board_view_id` (tab) and `key`,
     * the same way `board_view_id` itself was added as a partitioning key
     * over the plain per-board uniqueness in the prior migration — so `key`
     * uniqueness moves from per-tab to per-tab-per-scope, letting an item
     * column and a subitem column reuse the same key (e.g. both "status").
     */
    public function up(): void
    {
        Schema::table('board_columns', function (Blueprint $table) {
            $table->string('scope')->default('item')->after('type');
            $table->dropUnique(['board_view_id', 'key']);
        });

        Schema::table('board_columns', function (Blueprint $table) {
            $table->unique(['board_view_id', 'scope', 'key']);
            $table->index(['board_view_id', 'scope', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_columns', function (Blueprint $table) {
            $table->dropUnique(['board_view_id', 'scope', 'key']);
            $table->dropIndex(['board_view_id', 'scope', 'position']);
            $table->dropColumn('scope');
        });

        Schema::table('board_columns', function (Blueprint $table) {
            $table->unique(['board_view_id', 'key']);
        });
    }
};
