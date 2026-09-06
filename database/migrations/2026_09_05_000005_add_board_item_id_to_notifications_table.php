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
            // Links an "assigned" notification back to the exact row that
            // triggered it, so the email can surface the item/table/view/
            // workspace breadcrumb via the item's own relationships instead
            // of duplicating those labels as flat columns here.
            $table->unsignedBigInteger('board_item_id')->nullable()->after('board_id');
            $table->foreign('board_item_id')->references('id')->on('board_items')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['board_item_id']);
            $table->dropColumn('board_item_id');
        });
    }
};
