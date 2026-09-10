<?php

namespace App\Console\Commands\User;

use App\Http\Controllers\Admin\Role\RoleController;
use App\Http\Controllers\Workspace\WorkspaceMemberController;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AuditLogger;
use App\Support\WorkspacePermissionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// php artisan admin:assign-role
class AssignRole extends Command
{
    protected $signature = 'admin:assign-role
        {email? : The email of the user}
        {--role= : The name of the role to assign (a system role or a workspace role)}
        {--workspace= : The workspace slug or id, required when assigning a workspace role}
        {--sync : For a system role, replace all of the user\'s current system roles with this one instead of adding it}';

    protected $description = "Assign a role to a user, whether it's one of the app's system roles or one of a workspace's member roles";

    /**
     * The application's system role names (e.g. super_admin, admin), scoped to
     * the user account itself.
     *
     * @var array<int, string>
     */
    private array $system_role_names = [];

    /**
     * The catalog's assignable workspace role names (e.g. member, viewer),
     * scoped to a single workspace membership.
     *
     * @var array<int, string>
     */
    private array $workspace_role_names = [];

    public function handle(): int
    {
        $this->info('=== Assign User Role ===');
        $this->newLine();

        $this->system_role_names = Role::pluck('name')->all();
        $this->workspace_role_names = WorkspacePermissionCatalog::invitableRoleIds();

        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $role_name = $this->resolveRoleName();
        if ($role_name === null) {
            return self::FAILURE;
        }

        if (in_array($role_name, $this->system_role_names, true)) {
            return $this->assignSystemRole($user, $role_name);
        }

        return $this->assignWorkspaceRole($user, $role_name);
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
     * Resolve the role name from the --role option, prompting for a selection
     * across both role catalogs if missing.
     */
    private function resolveRoleName(): ?string
    {
        $role_name = $this->option('role');

        if ($role_name === null) {
            $choices = [
                ...array_map(fn (string $name) => "{$name} (system role)", $this->system_role_names),
                ...array_map(fn (string $name) => "{$name} (workspace role)", $this->workspace_role_names),
            ];

            if (empty($choices)) {
                $this->error('No roles exist yet. Seed roles first (e.g. RolePermissionSeeder).');

                return null;
            }

            $choice = $this->choice('Select a role to assign', $choices);
            $choice = is_array($choice) ? (string) reset($choice) : (string) $choice;

            return (string) preg_replace('/ \((system|workspace) role\)$/', '', $choice);
        }

        if (in_array($role_name, $this->system_role_names, true) || in_array($role_name, $this->workspace_role_names, true)) {
            return $role_name;
        }

        $available_roles = implode(', ', [...$this->system_role_names, ...$this->workspace_role_names]);
        $this->error("Role '{$role_name}' does not exist. Available roles: {$available_roles}.");

        return null;
    }

    /**
     * Assign one of the app's system roles (e.g. super_admin, admin) to the
     * user's account, mirroring {@see RoleController::assignRole()}.
     */
    private function assignSystemRole(User $user, string $role_name): int
    {
        if ($this->option('sync')) {
            $user->syncRoles([$role_name]);
        } else {
            $user->assignRole($role_name);
        }

        AuditLogger::log(
            'role.assigned',
            "Assigned role \"{$role_name}\" to {$user->full_name}.",
            metadata: ['target_user_id' => $user->id, 'role' => $role_name]
        );

        $this->newLine();
        $this->info("System role '{$role_name}' assigned to {$user->email}.");

        $this->table(
            ['Field', 'Value'],
            [
                ['Email', $user->email],
                ['Name', $user->full_name],
                ['System roles', $user->roles()->pluck('name')->implode(', ')],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Assign one of a workspace's member roles (e.g. owner, member, viewer) to
     * the user's membership in that workspace, mirroring
     * {@see WorkspaceMemberController::update()}.
     */
    private function assignWorkspaceRole(User $user, string $role_name): int
    {
        $workspace = $this->resolveWorkspace($user);
        if ($workspace === null) {
            return self::FAILURE;
        }

        $members = DB::table('workspace_user')->where('workspace_id', $workspace->id)->get();
        $membership = $members->firstWhere('user_id', $user->id);

        if ($membership === null) {
            $workspace->users()->attach($user->id, ['role' => $role_name]);
        } else {
            $blocking_reason = $this->soleOwnerDemotionReason($members, $user, (string) $membership->role, $role_name);

            if ($blocking_reason !== null) {
                $this->error($blocking_reason);

                return self::FAILURE;
            }

            $workspace->users()->updateExistingPivot($user->id, ['role' => $role_name]);
        }

        $this->newLine();
        $this->info("Workspace role '{$role_name}' assigned to {$user->email} in workspace '{$workspace->name}'.");

        $this->table(
            ['Field', 'Value'],
            [
                ['Email', $user->email],
                ['Name', $user->full_name],
                ['Workspace', $workspace->name],
                ['Workspace role', $role_name],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Resolve the workspace from the --workspace option (slug or id),
     * prompting for a selection among the user's workspaces if missing.
     */
    private function resolveWorkspace(User $user): ?Workspace
    {
        $identifier = $this->option('workspace');

        if ($identifier !== null) {
            $workspace = Workspace::where('slug', $identifier)->orWhere('id', $identifier)->first();

            if ($workspace === null) {
                $this->error("Workspace '{$identifier}' does not exist.");

                return null;
            }

            return $workspace;
        }

        $member_workspaces = $user->workspaces()->get();

        if ($member_workspaces->isEmpty()) {
            $this->error("{$user->email} does not belong to any workspace yet. Pass --workspace to add them to one.");

            return null;
        }

        if ($member_workspaces->count() === 1) {
            return $member_workspaces->first();
        }

        $labels = $member_workspaces
            ->map(fn (Workspace $workspace) => "{$workspace->name} ({$workspace->slug})")
            ->all();

        $choice = $this->choice('Select the workspace', $labels);
        $choice = is_array($choice) ? (string) reset($choice) : (string) $choice;

        preg_match('/\(([^()]+)\)$/', $choice, $matches);
        $slug = $matches[1] ?? null;

        return $member_workspaces->firstWhere('slug', $slug);
    }

    /**
     * Block demoting a workspace's sole remaining owner, the same invariant
     * enforced in {@see WorkspaceMemberController::update()}.
     * Returns the reason the change is blocked, or null when it's safe to proceed.
     *
     * @param  Collection<int, \stdClass>  $members
     */
    private function soleOwnerDemotionReason(Collection $members, User $user, string $current_role, string $new_role): ?string
    {
        if ($current_role !== 'owner' || $new_role === 'owner') {
            return null;
        }

        $other_owner_exists = $members->contains(
            fn ($row) => $row->user_id !== $user->id && $row->role === 'owner'
        );

        if ($other_owner_exists) {
            return null;
        }

        return "Assign another owner before changing this member's role.";
    }
}
