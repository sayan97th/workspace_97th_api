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
        Schema::table('notifications', function (Blueprint $table) {
            // Set by "Remind me later": the notification stays hidden from the
            // bell until this moment, then `notifications:wake-snoozed`
            // resurfaces it as unread.
            $table->timestamp('snoozed_until')->nullable()->after('dismissed_at');

            // Backs the bell drawer's cursor-paginated list (newest first, per user).
            $table->index(['user_id', 'created_at', 'id'], 'notifications_user_created_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_created_id_index');
            $table->dropColumn('snoozed_until');
        });
    }
};
