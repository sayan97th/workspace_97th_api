<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A read only public link to one board view, optionally protected by a
     * password. At most one link per view.
     */
    public function up(): void
    {
        Schema::create('board_view_share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->foreignId('board_view_id')->unique()->constrained('board_views')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('password')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_view_share_links');
    }
};
