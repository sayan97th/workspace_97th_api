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
        Schema::table('board_item_comments', function (Blueprint $table) {
            // Null (the common case) or past = published immediately. A future
            // value hides the comment from the item's comment thread and the
            // Update Feed until App\Services\Feed\FeedService::publishDue()
            // clears it back to null.
            $table->timestamp('scheduled_at')->nullable()->after('body');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_item_comments', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
        });
    }
};
