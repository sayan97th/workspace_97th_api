<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A short, optional explanation of what a tab is for, shown in the tab's
     * hover card and in the "Manage views" panel.
     */
    public function up(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->text('description')->nullable()->after('emoji');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
