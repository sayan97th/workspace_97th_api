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
        Schema::create('board_import_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->foreignId('board_view_id')->constrained('board_views')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('board_groups')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('import_token', 36);
            $table->string('file_name');
            $table->string('status')->default('queued'); // queued|processing|completed|failed|cancelled
            $table->json('options'); // mappings/target_group_id/new_group_name/duplicate_mode/match_source_index — see BoardItemImportService::commit()
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('columns_created')->default(0);
            $table->boolean('cancel_requested')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['board_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_import_jobs');
    }
};
