<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * - `board_automation_run_changes`: every change one automation run made inside the app (a
     *   cell value, a move, an archive, a delete, a rename, an item it created), in order, so the
     *   run can be undone from the run history.
     * - Run logs gain `undone_at`/`undone_by_id`, set once the run they belong to was undone.
     * - Automations gain `consecutive_failures`, reset by every run that does not fail, and
     *   `last_failed_at`. The board setting `auto_pause_after_failures` pauses an automation once
     *   it fails that many times in a row (null for the default, 0 to never pause).
     */
    public function up(): void
    {
        Schema::create('board_automation_run_changes', function (Blueprint $table) {
            $table->id();
            $table->string('run_uuid', 36)->index();
            $table->foreignId('automation_id')->nullable()->constrained('board_automations')->nullOnDelete();
            $table->unsignedBigInteger('board_id')->index();
            $table->unsignedBigInteger('board_item_id')->nullable()->index();
            $table->string('kind', 20);
            $table->unsignedBigInteger('column_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
        });

        Schema::table('board_automation_run_logs', function (Blueprint $table) {
            $table->timestamp('undone_at')->nullable()->after('retry_of_id');
            $table->unsignedBigInteger('undone_by_id')->nullable()->after('undone_at');
        });

        Schema::table('board_automations', function (Blueprint $table) {
            $table->unsignedInteger('consecutive_failures')->default(0)->after('failure_alert');
            $table->timestamp('last_failed_at')->nullable()->after('consecutive_failures');
        });

        Schema::table('board_automation_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('auto_pause_after_failures')->nullable()->after('holidays');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_automation_settings', function (Blueprint $table) {
            $table->dropColumn('auto_pause_after_failures');
        });

        Schema::table('board_automations', function (Blueprint $table) {
            $table->dropColumn(['consecutive_failures', 'last_failed_at']);
        });

        Schema::table('board_automation_run_logs', function (Blueprint $table) {
            $table->dropColumn(['undone_at', 'undone_by_id']);
        });

        Schema::dropIfExists('board_automation_run_changes');
    }
};
