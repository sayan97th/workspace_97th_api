<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Points a notification at the comment that triggered it, so the bell can
     * reply to that thread inline. `comment_kind` says which table `comment_id`
     * lives in ("item" or "board"). No foreign key, the comment may be deleted
     * while the notification stays.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('comment_id')->nullable()->after('board_item_id');
            $table->string('comment_kind', 8)->nullable()->after('comment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['comment_id', 'comment_kind']);
        });
    }
};
