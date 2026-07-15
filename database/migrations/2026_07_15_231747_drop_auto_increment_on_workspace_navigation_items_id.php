<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `WorkspaceNavigationItem` now assigns its own id (a random 10-digit number,
 * see App\Concerns\HasRandomBigId) instead of relying on the database to
 * generate one. Dropping AUTO_INCREMENT makes that explicit at the schema
 * level: if the application ever forgot to set an id, the insert would fail
 * loudly instead of silently falling back to a sequential value.
 *
 * SQLite (used in tests) has no equivalent to drop without rebuilding the
 * table, and — unlike MySQL — happily accepts an explicit value for an
 * `INTEGER PRIMARY KEY AUTOINCREMENT` column without ever falling back to
 * auto-assignment as long as one is provided, so there's nothing to change
 * there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // `id` is referenced by this table's own `parent_id` foreign key, so
        // MySQL refuses to MODIFY it while that constraint is active.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::statement('ALTER TABLE workspace_navigation_items MODIFY id BIGINT UNSIGNED NOT NULL');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::statement('ALTER TABLE workspace_navigation_items MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
