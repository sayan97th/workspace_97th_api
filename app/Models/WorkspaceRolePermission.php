<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single (role, permission_key) grant for Manage Workspace's "Permissions"
 * tab — see {@link \App\Support\WorkspacePermissionCatalog} for the catalog
 * of roles/permissions this table's rows are constrained to.
 *
 * @property int $id
 * @property string $role
 * @property string $permission_key
 * @property bool $allowed
 */
#[Fillable(['role', 'permission_key', 'allowed'])]
class WorkspaceRolePermission extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed' => 'boolean',
        ];
    }
}
