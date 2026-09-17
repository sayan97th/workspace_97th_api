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
        Schema::table('board_comments', function (Blueprint $table) {
            // Pinned updates sort ahead of the rest of the thread and the
            // Update Feed, mirroring `board_views.pinned`.
            $table->boolean('pinned')->default(false)->after('edited_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_comments', function (Blueprint $table) {
            $table->dropColumn('pinned');
        });
    }
};
