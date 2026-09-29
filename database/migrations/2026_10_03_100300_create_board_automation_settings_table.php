<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Board wide automation settings: "Pause all automations" and the working calendar (working
     * weekdays and holidays) that date triggers and date actions can skip non working days with.
     */
    public function up(): void
    {
        Schema::create('board_automation_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('board_id')->unique();
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('paused_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('workdays')->nullable();
            $table->json('holidays')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_automation_settings');
    }
};
