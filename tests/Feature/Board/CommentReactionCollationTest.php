<?php

use Illuminate\Support\Facades\DB;

/**
 * Guards the fix from `2026_08_16_140000_use_binary_collation_for_comment_reaction_emoji`:
 * MySQL's `utf8mb4_unicode_ci` (this app's default collation) has no weight
 * table entries above the Basic Multilingual Plane, so it collates every
 * emoji outside it (almost all of them) as equal to one another —
 * `'👍' = '🎉'` is true under that collation. That made the reaction toggle
 * endpoints delete the wrong user's-own reaction instead of adding a new
 * one whenever someone reacted with a second astral-plane emoji. The two
 * `emoji` columns must stay on `utf8mb4_bin` (exact-byte comparison) to
 * keep that fixed.
 *
 * Only meaningful against a real MySQL connection — sqlite (what the rest
 * of this suite runs on) has no `utf8mb4_*` collations, and its own default
 * already compares by exact bytes, so this test skips itself there rather
 * than asserting nothing.
 */
test('the comment reaction emoji columns use an exact-byte collation', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Only meaningful against a real MySQL connection.');
    }

    foreach (['board_item_comment_reactions', 'board_comment_reactions'] as $table) {
        $column = DB::selectOne(
            'SELECT COLLATION_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'emoji']
        );

        expect($column->COLLATION_NAME)->toBe('utf8mb4_bin');
    }
});
