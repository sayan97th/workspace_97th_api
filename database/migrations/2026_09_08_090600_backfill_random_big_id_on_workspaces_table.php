<?php

use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing workspaces were assigned small sequential ids (1, 2, 3, ...) by the
 * old auto-increment column, which makes them trivial to guess/enumerate from
 * the `/workspaces/{workspace_id}/...` URLs and the `by-id` API route. This
 * reassigns every existing workspace a random 10-digit id (see
 * App\Concerns\HasRandomBigId, already used by new rows once
 * `2026_09_08_090700_drop_auto_increment_on_workspaces_id` lands) and cascades
 * the change to every table that stores a `workspace_id` foreign key, since
 * none of those relations are set up with ON UPDATE CASCADE.
 *
 * Only runs against MySQL: tests boot from fresh migrations against an empty
 * sqlite database, so there is nothing here to backfill there.
 */
return new class extends Migration
{
    /**
     * Tables (other than `workspaces` itself) that store a workspace id and
     * need it rewritten in lockstep, keyed by their foreign-key column name.
     */
    private const REFERENCING_TABLES = [
        'workspace_navigation_items' => 'workspace_id',
        'workspace_user' => 'workspace_id',
        'workspace_invitations' => 'workspace_id',
        'workspace_nav_collapse_states' => 'workspace_id',
        'users' => 'last_active_workspace_id',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            DB::transaction(function () {
                Workspace::withTrashed()->pluck('id')->each(function (int $old_id) {
                    $new_id = Workspace::generateUniqueRandomId();

                    DB::table('workspaces')->where('id', $old_id)->update(['id' => $new_id]);

                    foreach (self::REFERENCING_TABLES as $table => $column) {
                        DB::table($table)->where($column, $old_id)->update([$column => $new_id]);
                    }
                });
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-way: the original sequential ids aren't recorded anywhere, so
        // there's nothing to restore them from.
    }
};
