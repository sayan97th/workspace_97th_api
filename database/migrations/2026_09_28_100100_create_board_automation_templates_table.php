<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "Save as template" on an automation card. A template is a board level copy of an
     * automation's definition (trigger, conditions and actions), shown in the Create tab's
     * "Saved templates" category, from which a new automation is built.
     */
    public function up(): void
    {
        Schema::create('board_automation_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('definition');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['board_id', 'created_at'], 'board_automation_templates_board_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_automation_templates');
    }
};
