<?php

namespace Database\Seeders;

use App\Models\WorkspaceRolePermission;
use App\Support\WorkspacePermissionCatalog;
use Illuminate\Database\Seeder;

class WorkspacePermissionSeeder extends Seeder
{
    /**
     * Seeds every (role, permission_key) pair in the catalog with its
     * default grant, so the Permissions tab has real values before anyone
     * has toggled anything. Idempotent — safe to re-run.
     */
    public function run(): void
    {
        $defaults = WorkspacePermissionCatalog::defaults();

        foreach ($defaults as $role => $permissions) {
            foreach ($permissions as $permission_key => $allowed) {
                WorkspaceRolePermission::firstOrCreate(
                    ['role' => $role, 'permission_key' => $permission_key],
                    ['allowed' => $allowed]
                );
            }
        }
    }
}
