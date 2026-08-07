<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Deliberately doesn't use WithoutModelEvents: WorkspaceSeeder (called
    // below) relies on the `creating` hook from HasRandomBigId to assign
    // navigation item ids, and that trait wraps this whole run() — including
    // nested seeder calls — in Model::withoutEvents() regardless of whether
    // WorkspaceSeeder opts back in on its own.

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(WorkspaceSeeder::class);
        $this->call(WorkspacePermissionSeeder::class);
        $this->call(BoardContentSeeder::class);
        $this->call(ClientHubViewsSeeder::class);
        $this->call(ClientHubContentSeeder::class);
        $this->call(WorkspaceContentCreatorSeeder::class);
        $this->call(AccountTeamSeeder::class);
    }
}
