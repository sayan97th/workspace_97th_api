<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Turns the per user tab order row into the viewer's tab preferences for
     * one board: the order becomes optional ("Reset to default order" clears
     * it), and the row also keeps the tabs hidden for that viewer and the tab
     * they want opened first.
     */
    public function up(): void
    {
        Schema::table('board_view_user_orders', function (Blueprint $table) {
            $table->json('view_order')->nullable()->change();
            $table->json('hidden_view_ids')->nullable()->after('view_order');
            $table->unsignedBigInteger('default_view_id')->nullable()->after('hidden_view_ids');

            $table->foreign('default_view_id')->references('id')->on('board_views')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_view_user_orders', function (Blueprint $table) {
            $table->dropForeign(['default_view_id']);
            $table->dropColumn(['hidden_view_ids', 'default_view_id']);
        });
    }
};
