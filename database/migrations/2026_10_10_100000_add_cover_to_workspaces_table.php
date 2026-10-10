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
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('cover_path')->nullable()->after('avatar_thumbnail_path');
            // Vertical focal point (0 = top, 100 = bottom) used to frame the
            // cover inside the wide Manage Workspace banner.
            $table->unsignedTinyInteger('cover_position_y')->default(50)->after('cover_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['cover_path', 'cover_position_y']);
        });
    }
};
