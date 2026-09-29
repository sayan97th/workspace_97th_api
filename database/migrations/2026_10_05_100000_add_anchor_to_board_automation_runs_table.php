<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `anchor` tells apart two stretches that started on the same day for the "status is stuck"
     * and "item is not updated" triggers, the moment the stretch began. Date triggers leave it
     * empty, so their one run per day stays unique as before.
     *
     * The new unique index is added before the old one is dropped, MySQL needs an index that
     * starts with `automation_id` for its foreign key at all times.
     */
    public function up(): void
    {
        Schema::table('board_automation_runs', function (Blueprint $table) {
            $table->string('anchor', 40)->default('')->after('ran_on');
        });

        Schema::table('board_automation_runs', function (Blueprint $table) {
            $table->unique(['automation_id', 'board_item_id', 'ran_on', 'anchor'], 'board_automation_runs_stretch_unique');
        });

        Schema::table('board_automation_runs', function (Blueprint $table) {
            $table->dropUnique(['automation_id', 'board_item_id', 'ran_on']);
        });
    }

    public function down(): void
    {
        Schema::table('board_automation_runs', function (Blueprint $table) {
            $table->unique(['automation_id', 'board_item_id', 'ran_on']);
        });

        Schema::table('board_automation_runs', function (Blueprint $table) {
            $table->dropUnique('board_automation_runs_stretch_unique');
            $table->dropColumn('anchor');
        });
    }
};
