<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A `calendar`-type view stores its settings (which Date/Timeline columns
     * it shows, what colors its items, the group filter, the first day of the
     * week) in `calendar_config`.
     */
    public function up(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->json('calendar_config')->nullable()->after('form_config');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->dropColumn('calendar_config');
        });
    }
};
