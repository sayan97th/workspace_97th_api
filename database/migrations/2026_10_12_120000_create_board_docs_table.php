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
        // The body of a Doc (a navigation leaf with `view_key = doc`), one row per doc.
        // Kept out of `workspace_navigation_items` so loading the sidebar tree never reads it.
        Schema::create('board_docs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->unique()->constrained('workspace_navigation_items')->cascadeOnDelete();
            // Editor JSON document (ProseMirror/Tiptap), stored as text.
            $table->longText('content')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_docs');
    }
};
