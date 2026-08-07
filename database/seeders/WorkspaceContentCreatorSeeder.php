<?php

namespace Database\Seeders;

use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkspaceContentCreatorSeeder extends Seeder
{
    /**
     * Every other seeder in this file creates boards/docs (navigation
     * items) without a `created_by_id` — they're demo data, not something a
     * real person clicked "create" on. Manage Workspace's Content/Recents
     * tabs show a creator avatar for each row, so anything still missing one
     * gets attributed to its workspace's owner (or, failing that, its
     * earliest member) rather than showing a blank avatar forever.
     */
    public function run(): void
    {
        Workspace::all()->each(function (Workspace $workspace) {
            $fallback_creator_id = DB::table('workspace_user')
                ->where('workspace_id', $workspace->id)
                ->where('role', 'owner')
                ->orderBy('created_at')
                ->value('user_id')
                ?? DB::table('workspace_user')
                    ->where('workspace_id', $workspace->id)
                    ->orderBy('created_at')
                    ->value('user_id');

            if ($fallback_creator_id === null) {
                return;
            }

            $workspace->navigationItems()
                ->whereNull('created_by_id')
                ->update(['created_by_id' => $fallback_creator_id]);
        });
    }
}
