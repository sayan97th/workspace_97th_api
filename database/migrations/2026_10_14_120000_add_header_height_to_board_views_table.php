<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The board toolbar's "Header height" setting (single, double or triple),
     * saved per view next to `row_height`. Taller headers let long column
     * titles wrap onto two or three lines instead of being cut off.
     */
    public function up(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->string('header_height')->default('single')->after('row_height');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->dropColumn('header_height');
        });
    }
};
