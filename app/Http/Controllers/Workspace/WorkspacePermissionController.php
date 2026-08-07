<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\UpdateWorkspacePermissionRequest;
use App\Models\WorkspaceRolePermission;
use App\Support\WorkspacePermissionCatalog;
use Illuminate\Http\JsonResponse;

/**
 * The default-role permission matrix shown in Manage Workspace's
 * "Permissions" tab. The catalog (roles/groups/labels) is the same across
 * every workspace ({@link WorkspacePermissionCatalog}); only the grants
 * (which role can do what) are stored data, in {@link WorkspaceRolePermission}.
 */
class WorkspacePermissionController extends Controller
{
    /**
     * GET /api/workspace-permissions
     */
    public function index(): JsonResponse
    {
        $grants = WorkspaceRolePermission::query()
            ->get()
            ->groupBy('role')
            ->map(fn ($rows) => $rows->pluck('allowed', 'permission_key'));

        $defaults = WorkspacePermissionCatalog::defaults();

        $matrix = [];
        foreach (WorkspacePermissionCatalog::roleIds() as $role) {
            $matrix[$role] = [];
            foreach (WorkspacePermissionCatalog::permissionKeys() as $permission_key) {
                $matrix[$role][$permission_key] = (bool) (
                    $grants[$role][$permission_key] ?? $defaults[$role][$permission_key] ?? false
                );
            }
        }

        return response()->json([
            'roles' => WorkspacePermissionCatalog::roles(),
            'groups' => WorkspacePermissionCatalog::groups(),
            'matrix' => $matrix,
        ]);
    }

    /**
     * PATCH /api/workspace-permissions
     *
     * Toggles a single (role, permission_key) grant. Restricted to staff via
     * the `role:super_admin,admin,staff` route middleware — this configures
     * the default permission set applied across every workspace, not a
     * single workspace's own settings.
     */
    public function update(UpdateWorkspacePermissionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        WorkspaceRolePermission::updateOrCreate(
            ['role' => $validated['role'], 'permission_key' => $validated['permission_key']],
            ['allowed' => $validated['allowed']]
        );

        return response()->json([
            'message' => 'Permission updated successfully.',
        ]);
    }
}
