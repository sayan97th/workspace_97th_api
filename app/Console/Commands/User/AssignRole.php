<?php

namespace App\Console\Commands\User;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

// php artisan admin:assign-role
class AssignRole extends Command
{
    protected $signature = 'admin:assign-role
        {email? : The email of the user}
        {--role= : The name of the role to assign}
        {--sync : Replace all of the user\'s current roles with this one instead of adding it}';

    protected $description = 'Assign a role to a user';

    public function handle(): int
    {
        $this->info('=== Assign User Role ===');
        $this->newLine();

        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $role = $this->resolveRole();
        if ($role === null) {
            return self::FAILURE;
        }

        if ($this->option('sync')) {
            $user->syncRoles([$role->name]);
        } else {
            $user->assignRole($role->name);
        }

        $this->newLine();
        $this->info("Role '{$role->name}' assigned to {$user->email}.");

        $this->table(
            ['Field', 'Value'],
            [
                ['Email', $user->email],
                ['Name', $user->full_name],
                ['Roles', $user->roles()->pluck('name')->implode(', ')],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Resolve the target user from the email argument, asking for it if missing.
     */
    private function resolveUser(): ?User
    {
        $email = $this->argument('email') ?? $this->ask('Email address of the user');

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email']]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('email'));

            return null;
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email [{$email}].");

            return null;
        }

        return $user;
    }

    /**
     * Resolve the role from the --role option, prompting for a selection if missing.
     */
    private function resolveRole(): ?Role
    {
        $roleName = $this->option('role');

        if ($roleName === null) {
            $availableRoles = Role::pluck('name')->toArray();

            if (empty($availableRoles)) {
                $this->error('No roles exist yet. Seed roles first (e.g. RolePermissionSeeder).');

                return null;
            }

            $roleName = $this->choice('Select a role to assign', $availableRoles);
        }

        $role = Role::where('name', $roleName)->first();

        if ($role === null) {
            $this->error("Role '{$roleName}' does not exist.");

            return null;
        }

        return $role;
    }
}
