<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * - `condition_operator`/`condition_groups`: the "and only if" rules can be combined with Or,
     *   and grouped, the same shape as the toolbar's Advanced filter groups.
     * - `else_actions`: the "Otherwise" branch, run when the item does not pass the conditions.
     * - `failure_alert`: how the owner hears about a failed run (`app`, `app_and_email`, `none`).
     * - `state`: what an automation remembers between runs, e.g. who a round robin assigned last.
     * - Run logs gain `run_uuid` (every step of one execution shares it), `branch`, `step_index`,
     *   `context` (what the trigger knew, so a failed step can be retried) and `retry_of_id`.
     */
    public function up(): void
    {
        Schema::table('board_automations', function (Blueprint $table) {
            $table->string('condition_operator', 3)->default('and')->after('conditions');
            $table->json('condition_groups')->nullable()->after('condition_operator');
            $table->json('else_actions')->nullable()->after('actions');
            $table->string('failure_alert', 20)->default('app')->after('importance');
            $table->json('state')->nullable()->after('paused_reason');
        });

        Schema::table('board_automation_run_logs', function (Blueprint $table) {
            $table->string('run_uuid', 36)->nullable()->after('id')->index();
            $table->string('branch', 10)->default('then')->after('action_type');
            $table->unsignedSmallInteger('step_index')->nullable()->after('branch');
            $table->json('context')->nullable()->after('message');
            $table->unsignedBigInteger('retry_of_id')->nullable()->after('context');
            $table->index(['board_item_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_automation_run_logs', function (Blueprint $table) {
            $table->dropIndex(['board_item_id', 'created_at']);
            $table->dropIndex(['run_uuid']);
            $table->dropColumn(['run_uuid', 'branch', 'step_index', 'context', 'retry_of_id']);
        });

        Schema::table('board_automations', function (Blueprint $table) {
            $table->dropColumn(['condition_operator', 'condition_groups', 'else_actions', 'failure_alert', 'state']);
        });
    }
};
