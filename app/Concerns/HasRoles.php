<?php

namespace App\Concerns;

use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasRoles
{
    /**
     * Get the roles assigned to the user.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role');
    }

    /**
     * Determine if the user has the given role, or any of the given roles.
     *
     * @param  string|array<array-key, string>  $roles
     */
    public function hasRole(string|array $roles): bool
    {
        if (is_string($roles)) {
            return $this->roles->contains('name', $roles);
        }

        foreach ($roles as $role) {
            if ($this->roles->contains('name', $role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the user has the given permission through any of their roles.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->hasRole('super_admin')) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', function ($query) use ($permission) {
                $query->where('name', $permission);
            })
            ->exists();
    }

    /**
     * Assign the given role to the user.
     */
    public function assignRole(string $roleName): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $this->roles()->syncWithoutDetaching([$role->id]);
    }

    /**
     * Remove the given role from the user.
     */
    public function removeRole(string $roleName): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $this->roles()->detach($role->id);
    }

    /**
     * Sync the user's roles to exactly the given role names.
     *
     * @param  array<int, string>  $roleNames
     */
    public function syncRoles(array $roleNames): void
    {
        $roleIds = Role::whereIn('name', $roleNames)->pluck('id');
        $this->roles()->sync($roleIds);
    }

    /**
     * Get the names of all permissions granted to the user through their roles.
     *
     * @return array<int, string>
     */
    public function getAllPermissions(): array
    {
        return $this->roles()
            ->with('permissions')
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('name')
            ->unique()
            ->values()
            ->toArray();
    }
}
