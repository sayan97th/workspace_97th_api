<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tab the user had open on their last visit, so the Home page's
     * "Recently visited" card can preview that tab (Kanban, Gantt, Chart, …)
     * instead of always drawing the Main table. Null until the user opens a
     * tab, and reset to null when that tab is deleted.
     */
    public function up(): void
    {
        Schema::table('board_visits', function (Blueprint $table) {
            $table->foreignId('board_view_id')->nullable()->after('board_id')->constrained('board_views')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('board_view_id');
        });
    }
};
