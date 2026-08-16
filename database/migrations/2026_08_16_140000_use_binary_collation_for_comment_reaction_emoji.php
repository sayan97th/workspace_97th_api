<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `utf8mb4_unicode_ci` (this app's default collation) has no weight table
 * entries for codepoints outside the Basic Multilingual Plane, i.e. almost
 * every emoji — so under that collation MySQL treats *all* such emoji as
 * equal to one another (`'👍' = '🎉'` collates true). That made
 * `where('emoji', $emoji)` in the reaction toggle endpoints match whatever
 * *other* astral-plane emoji the user had already reacted with on the same
 * comment and delete that one instead of adding the new reaction — the more
 * emoji reactions a comment collected, the more of the earlier ones this
 * could silently wipe out. Switching the column to `utf8mb4_bin` makes
 * equality exact-byte, which is what a reaction lookup actually needs (it's
 * a token match, not sorted or displayed text). sqlite (the test suite's
 * connection) has no `utf8mb4_bin` collation, but its own default column
 * collation (`BINARY`) already compares by exact bytes, so this only needs
 * to run against MySQL.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('board_item_comment_reactions', function (Blueprint $table) {
            $table->string('emoji')->collation('utf8mb4_bin')->change();
        });
        Schema::table('board_comment_reactions', function (Blueprint $table) {
            $table->string('emoji')->collation('utf8mb4_bin')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('board_item_comment_reactions', function (Blueprint $table) {
            $table->string('emoji')->collation('utf8mb4_unicode_ci')->change();
        });
        Schema::table('board_comment_reactions', function (Blueprint $table) {
            $table->string('emoji')->collation('utf8mb4_unicode_ci')->change();
        });
    }
};
