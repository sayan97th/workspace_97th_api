<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row every time an automation is created or its sentence, name, description or
     * importance is saved, so the Manage tab can show who changed what and restore an older one.
     */
    public function up(): void
    {
        Schema::create('board_automation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained('board_automations')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->json('changed_parts')->nullable();
            $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['automation_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_automation_versions');
    }
};
