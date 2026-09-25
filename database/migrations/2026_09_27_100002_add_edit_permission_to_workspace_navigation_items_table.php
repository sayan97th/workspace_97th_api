<?php

use App\Enums\BoardEditPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * monday.com's board permission modes: `everything`, `content`,
     * `assigned_items` and `view_only`. See {@see BoardEditPermission}.
     */
    public function up(): void
    {
        Schema::table('workspace_navigation_items', function (Blueprint $table) {
            $table->string('edit_permission', 32)->default('everything')->after('board_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_navigation_items', function (Blueprint $table) {
            $table->dropColumn('edit_permission');
        });
    }
};
