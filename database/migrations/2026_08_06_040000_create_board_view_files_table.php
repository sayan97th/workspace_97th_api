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
        Schema::create('board_view_files', function (Blueprint $table) {
            $table->id();
            // A `file_gallery`-type view's own files — deleting the tab cascades
            // to every file it holds, same as `board_item_comment_attachments`
            // cascades from its owning comment.
            $table->foreignId('board_view_id')->constrained('board_views')->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('extension', 10);
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index(['board_view_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_view_files');
    }
};
