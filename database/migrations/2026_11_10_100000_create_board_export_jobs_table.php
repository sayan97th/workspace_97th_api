<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per "Export board to Excel" run, see App\Models\BoardExportJob.
     */
    public function up(): void
    {
        Schema::create('board_export_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->foreignId('board_view_id')->constrained('board_views')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('board_groups')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued'); // queued|processing|completed|failed|cancelled
            $table->json('options'); // layout, include_subitems, include_hidden_columns, time_zone
            $table->string('file_name');
            $table->string('disk')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('column_count')->default(0);
            $table->boolean('cancel_requested')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['board_id', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_export_jobs');
    }
};
