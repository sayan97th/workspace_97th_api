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
        Schema::create('board_views', function (Blueprint $table) {
            // Random 10-digit id (App\Concerns\HasRandomBigId), not auto-increment —
            // this is the id that appears in `/boards/{board_id}/views/{id}` deep links.
            $table->unsignedBigInteger('id')->primary();
            $table->foreignId('board_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->string('label');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->json('filter_state')->nullable();
            $table->json('sort_state')->nullable();
            $table->string('group_by_option_id')->nullable();
            $table->json('hidden_column_ids')->nullable();
            $table->json('pinned_column_ids')->nullable();
            $table->string('row_height')->default('single');
            $table->json('conditional_color_rules')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['board_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_views');
    }
};
