<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Optional hex color of a sidebar folder, shown on its folder icon.
     */
    public function up(): void
    {
        Schema::table('workspace_navigation_items', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('icon');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_navigation_items', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
