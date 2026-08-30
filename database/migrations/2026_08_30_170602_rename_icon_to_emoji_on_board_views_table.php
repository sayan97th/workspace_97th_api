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
        // Replaces the old fixed icon-set picker (BOARD_VIEW_ICON_OPTIONS) with
        // a free-form emoji, so the column's meaning changes along with its
        // name — a tab can now carry any single emoji instead of a key into a
        // curated registry. Existing values (icon-set keys like "star") are
        // stale for the new feature and simply render as literal text until
        // reassigned, same as any other unset emoji would.
        Schema::table('board_views', function (Blueprint $table) {
            $table->renameColumn('icon', 'emoji');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->renameColumn('emoji', 'icon');
        });
    }
};
