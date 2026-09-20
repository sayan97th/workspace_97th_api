<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A resolved thread is a top-level update someone marked as done. The
     * drawers collapse it and offer to reopen it, `resolved_by_id` records who
     * closed it.
     */
    public function up(): void
    {
        foreach (['board_item_comments', 'board_comments'] as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->timestamp('resolved_at')->nullable()->after('pinned');
                $table->foreignId('resolved_by_id')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['board_item_comments', 'board_comments'] as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('resolved_by_id');
                $table->dropColumn('resolved_at');
            });
        }
    }
};
