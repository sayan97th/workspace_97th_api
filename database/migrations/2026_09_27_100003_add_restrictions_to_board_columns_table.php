<?php

use App\Services\Board\ColumnPermissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Column permissions. Each restriction is `null` (everyone) or
     * `{user_ids: int[], team_ids: int[]}` (only those people, plus the
     * board owners). See {@see ColumnPermissionService}.
     */
    public function up(): void
    {
        Schema::table('board_columns', function (Blueprint $table) {
            $table->json('edit_restriction')->nullable()->after('config');
            $table->json('view_restriction')->nullable()->after('edit_restriction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_columns', function (Blueprint $table) {
            $table->dropColumn(['edit_restriction', 'view_restriction']);
        });
    }
};
