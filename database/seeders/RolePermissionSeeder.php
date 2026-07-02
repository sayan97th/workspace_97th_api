<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['name' => 'super_admin', 'display_name' => 'Super Administrator', 'description' => 'Full system access'],
            ['name' => 'admin', 'display_name' => 'Admin', 'description' => 'Manages the platform and staff'],
            ['name' => 'staff', 'display_name' => 'Staff', 'description' => 'Operational team member'],
            ['name' => 'client', 'display_name' => 'Client', 'description' => 'Regular user'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role['name']], $role);
        }

        $permissions = [
            ['name' => 'users.view', 'display_name' => 'View Users'],
            ['name' => 'users.create', 'display_name' => 'Create Users'],
            ['name' => 'users.update', 'display_name' => 'Update Users'],
            ['name' => 'users.delete', 'display_name' => 'Delete Users'],
            ['name' => 'roles.view', 'display_name' => 'View Roles'],
            ['name' => 'roles.assign', 'display_name' => 'Assign Roles'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission['name']], $permission);
        }

        $super_admin = Role::where('name', 'super_admin')->first();
        $super_admin->permissions()->sync(Permission::pluck('id'));

        $admin = Role::where('name', 'admin')->first();
        $admin->permissions()->sync(
            Permission::whereIn('name', [
                'users.view', 'users.create', 'users.update',
                'roles.view', 'roles.assign',
            ])->pluck('id')
        );

        $staff = Role::where('name', 'staff')->first();
        $staff->permissions()->sync(
            Permission::whereIn('name', ['users.view'])->pluck('id')
        );

        $client = Role::where('name', 'client')->first();
        $client->permissions()->sync([]);
    }
}
