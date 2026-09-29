<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A cell change made by an automation keeps which automation made it, so the item's
     * activity reads "Automation: <name>" instead of crediting the person who set it off.
     * The name is copied so the entry stays readable after the automation is deleted.
     */
    public function up(): void
    {
        Schema::table('board_item_activities', function (Blueprint $table) {
            $table->foreignId('automation_id')->nullable()->after('user_id')->constrained('board_automations')->nullOnDelete();
            $table->string('automation_name', 255)->nullable()->after('automation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_item_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('automation_id');
            $table->dropColumn('automation_name');
        });
    }
};
