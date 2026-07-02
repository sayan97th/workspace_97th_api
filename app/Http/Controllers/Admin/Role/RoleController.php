<?php

namespace App\Http\Controllers\Admin\Role;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Role\AssignRoleRequest;
use App\Http\Requests\Admin\Role\RevokeRoleRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    /**
     * GET /api/admin/roles
     */
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:id,name,display_name')->get();

        return response()->json(['roles' => $roles]);
    }

    /**
     * POST /api/admin/roles/users/{user}/assign
     */
    public function assignRole(AssignRoleRequest $request, User $user): JsonResponse
    {
        $user->assignRole($request->validated('role'));
        $user->load('roles:id,name,display_name');

        return response()->json([
            'message' => 'Role assigned successfully.',
            'user' => $user,
        ]);
    }

    /**
     * POST /api/admin/roles/users/{user}/revoke
     */
    public function revokeRole(RevokeRoleRequest $request, User $user): JsonResponse
    {
        $user->removeRole($request->validated('role'));
        $user->load('roles:id,name,display_name');

        return response()->json([
            'message' => 'Role revoked successfully.',
            'user' => $user,
        ]);
    }
}
