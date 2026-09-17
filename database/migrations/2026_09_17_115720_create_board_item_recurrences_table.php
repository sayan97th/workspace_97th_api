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
        // A root item marked to auto-recreate itself on a schedule — see
        // `RecurringItemService::run()`. One row per item (a subitem can't
        // recur on its own, only as part of its root's own recreated
        // subtree), enforced by the unique `board_item_id`.
        Schema::create('board_item_recurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_item_id')->unique()->constrained('board_items')->cascadeOnDelete();
            $table->string('frequency'); // daily|weekly|monthly
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->date('next_run_date');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_item_recurrences');
    }
};
