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
        Schema::create('workspace_nav_collapse_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            // Ids of `workspace_navigation_items` (folders) this user currently has
            // collapsed in this workspace's sidebar — only the (typically few)
            // collapsed ones are stored, not one row per folder, so this stays
            // cheap even on a workspace with hundreds of folders.
            $table->json('collapsed_group_ids');
            $table->timestamps();

            $table->unique(['user_id', 'workspace_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_nav_collapse_states');
    }
};
