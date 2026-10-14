<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A `gantt`-type view stores its settings (which Timeline or Date column
     * places the bars, what colors them, the group filter, the group
     * summary, dependency, critical path and legend toggles) and its saved
     * baseline snapshot in `gantt_config`.
     */
    public function up(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->json('gantt_config')->nullable()->after('calendar_config');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->dropColumn('gantt_config');
        });
    }
};
