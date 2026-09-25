<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Personal layout of the workspace sidebar: which personal sections
     * (Home, My work, Favorites, Recent) are shown and in what order, and
     * which of them are collapsed. Null until the user changes anything.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('sidebar_preferences')->nullable()->after('sidebar_width');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sidebar_preferences');
        });
    }
};
