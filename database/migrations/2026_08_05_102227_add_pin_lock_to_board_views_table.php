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
            // Pinned tabs sort before unpinned ones (ahead of the primary tab's
            // own always-first placement) whenever the viewer has no personal
            // tab order saved — see `board_view_user_orders`.
            $table->boolean('pinned')->default(false)->after('is_primary');
            // While locked, nobody can rename/delete/duplicate the view or save
            // filter/sort/display changes to it (see BoardViewController).
            $table->boolean('is_locked')->default(false)->after('pinned');
            $table->foreignId('locked_by_id')->nullable()->after('is_locked')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_by_id');
            $table->dropColumn(['pinned', 'is_locked']);
        });
    }
};
