<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The sentence builder turns one automation into "When X, and only if Y, then A, B and C":
     * `trigger_config` holds what the trigger needs beyond its column and value (a status it
     * changes from, a date offset, a recurring schedule), `conditions` the "and only if" rules and
     * `actions` the ordered list of actions. `action_type`/`action_params` keep mirroring the first
     * action so the run history, usage numbers and older clients keep reading them.
     */
    public function up(): void
    {
        Schema::table('board_automations', function (Blueprint $table) {
            $table->json('trigger_config')->nullable()->after('trigger_value');
            $table->json('conditions')->nullable()->after('trigger_config');
            $table->json('actions')->nullable()->after('action_params');
            $table->string('importance', 16)->default('minor')->after('is_enabled');
            $table->text('description')->nullable()->after('name');
            $table->foreignId('owner_id')->nullable()->after('created_by_id')->constrained('users')->nullOnDelete();
            $table->timestamp('last_scheduled_run_at')->nullable()->after('owner_id');

            $table->index(['trigger_type', 'is_enabled'], 'board_automations_type_enabled_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_automations', function (Blueprint $table) {
            $table->dropIndex('board_automations_type_enabled_idx');
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['trigger_config', 'conditions', 'actions', 'importance', 'description', 'last_scheduled_run_at']);
        });
    }
};
