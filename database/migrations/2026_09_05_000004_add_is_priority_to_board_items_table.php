<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Flags a single item (or subitem) as a priority row — the per-row
     * counterpart of `board_groups.is_priority`. A client can be flagged
     * high-end at the whole-group level already; this lets a specific task
     * within any group (priority client or not) be called out the same way,
     * independent of any per-item Status/Priority column.
     */
    public function up(): void
    {
        Schema::table('board_items', function (Blueprint $table) {
            $table->boolean('is_priority')->default(false)->after('is_archived');
            $table->index(['group_id', 'is_priority', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_items', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'is_priority', 'position']);
            $table->dropColumn('is_priority');
        });
    }
};
