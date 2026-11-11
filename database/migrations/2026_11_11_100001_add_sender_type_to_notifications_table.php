<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who the bell shows as the sender, like monday.com's notification
     * creator: `user` for the person in `actor_id`, `automations` for what an
     * automation or a reminder sent, which monday lists as "Automations" with
     * the app logo even though a person's action triggered it. Existing
     * automation and due date reminder rows are backfilled.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('sender_type', 16)->default('user')->after('actor_id');
        });

        DB::table('notifications')
            ->whereIn('type', ['automations_notify', 'due_date_reminder'])
            ->update(['sender_type' => 'automations']);
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('sender_type');
        });
    }
};
