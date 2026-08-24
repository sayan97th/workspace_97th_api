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
            // Chart type, data source tab, group/split-by column ids and
            // aggregation function for a `chart`-type view — see
            // App\Services\Board\ChartDataService, which resolves sane
            // defaults for any key left null here.
            $table->json('chart_config')->nullable()->after('doc_content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->dropColumn('chart_config');
        });
    }
};
