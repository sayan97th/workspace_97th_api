<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `item_created`/`subitem_created` automations (see `BoardAutomation::TRIGGER_ITEM_CREATED`)
     * have no watched column at all, unlike `status_changed`/`date_arrived`/`person_assigned`.
     */
    public function up(): void
    {
        Schema::table('board_automations', function (Blueprint $table) {
            $table->foreignId('trigger_column_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_automations', function (Blueprint $table) {
            $table->foreignId('trigger_column_id')->nullable(false)->change();
        });
    }
};
