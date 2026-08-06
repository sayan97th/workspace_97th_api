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
        Schema::table('board_views', function (Blueprint $table) {
            // Markdown source for a `doc`-type view — the whole document lives
            // in this one column, unlike table/kanban tabs whose content is
            // scoped across board_columns/board_groups/board_items.
            $table->longText('doc_content')->nullable()->after('view_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->dropColumn('doc_content');
        });
    }
};
