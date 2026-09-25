<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Settings of the Workload tab (which tab, People, date and effort
     * columns it reads, capacity per person) and of the Dashboard tab (its
     * widgets and their layout). Null for every other kind of tab.
     */
    public function up(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->json('workload_config')->nullable()->after('chart_config');
            $table->json('dashboard_config')->nullable()->after('workload_config');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->dropColumn(['workload_config', 'dashboard_config']);
        });
    }
};
