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
        Schema::table('account_teams', function (Blueprint $table) {
            // Vertical focal point (0 = top, 100 = bottom) used to frame the cover inside the
            // wide banner at the top of the team's page.
            $table->unsignedTinyInteger('cover_position_y')->default(50)->after('cover_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_teams', function (Blueprint $table) {
            $table->dropColumn('cover_position_y');
        });
    }
};
