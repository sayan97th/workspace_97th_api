<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The board Activity log can undo an entry, monday.com style. A cell
     * change keeps the raw values on both sides (the display text alone can
     * not be written back), `is_undoable` tells a row with raw values apart
     * from an older row recorded before this change, and `undone_at` marks an
     * entry that was already undone so its Undo button is turned off.
     */
    public function up(): void
    {
        Schema::table('board_item_activities', function (Blueprint $table) {
            $table->json('old_value')->nullable()->after('new_display');
            $table->json('new_value')->nullable()->after('old_value');
            $table->boolean('is_undoable')->default(false)->after('new_value');
            $table->timestamp('undone_at')->nullable()->after('is_undoable');
            $table->index(['column_id', 'created_at']);
        });

        Schema::table('board_activity_logs', function (Blueprint $table) {
            $table->timestamp('undone_at')->nullable()->after('meta');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_item_activities', function (Blueprint $table) {
            $table->dropIndex(['column_id', 'created_at']);
            $table->dropColumn(['old_value', 'new_value', 'is_undoable', 'undone_at']);
        });

        Schema::table('board_activity_logs', function (Blueprint $table) {
            $table->dropColumn('undone_at');
        });
    }
};
