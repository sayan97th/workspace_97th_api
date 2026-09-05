<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Flags a group (table) as a priority client — separate from any
     * per-item Status/Priority column, this marks the *whole client* as
     * high-end so their tasks can be surfaced above everyone else's, which a
     * per-item column has no way to express.
     */
    public function up(): void
    {
        Schema::table('board_groups', function (Blueprint $table) {
            $table->boolean('is_priority')->default(false)->after('accent_color');
            $table->index(['board_view_id', 'is_priority', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_groups', function (Blueprint $table) {
            $table->dropIndex(['board_view_id', 'is_priority', 'position']);
            $table->dropColumn('is_priority');
        });
    }
};
