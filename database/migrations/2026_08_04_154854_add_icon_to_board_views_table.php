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
        Schema::table('board_views', function (Blueprint $table) {
            // References an entry in the frontend's BOARD_VIEW_ICON_OPTIONS registry
            // (@/components/board/boardViewIcons.tsx) — null renders the default
            // per-position tab icon (table icon on the primary tab, none otherwise).
            $table->string('icon')->nullable()->after('label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
