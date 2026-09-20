<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Department\AssignDepartmentMembersRequest;
use App\Http\Requests\Admin\Department\AssignDepartmentOwnersRequest;
use App\Http\Requests\Admin\Department\StoreDepartmentRequest;
use App\Http\Requests\Admin\Department\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    /**
     * GET /api/admin/departments
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');

        $query = Department::withCount('users')->with('owners')->orderBy('name');

        if ($search !== null && $search !== '') {
            $query->where('name', 'LIKE', '%'.$search.'%');
        }

        return response()->json([
            'data' => DepartmentResource::collection($query->get()),
        ]);
    }

    /**
     * POST /api/admin/departments
     */
    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::create([
            ...$request->validated(),
            'created_by_id' => $request->user()->id,
        ]);
        $this->loadDepartmentAggregates($department);

        AuditLogger::log('department.created', "Created department \"{$department->name}\".", $request->user());

        return response()->json([
            'message' => 'Department created successfully.',
            'department' => new DepartmentResource($department),
        ], 201);
    }

    /**
     * PATCH /api/admin/departments/{department}
     */
    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $department->update($request->validated());
        $this->loadDepartmentAggregates($department);

        AuditLogger::log('department.updated', "Updated department \"{$department->name}\".", $request->user());

        return response()->json([
            'message' => 'Department updated successfully.',
            'department' => new DepartmentResource($department),
        ]);
    }

    /**
     * DELETE /api/admin/departments/{department}
     *
     * Soft-deletes the department. Its users are left with the now-orphaned
     * `department_id` pointing at a trashed row rather than being cleared, so restoring the
     * department also restores everyone's assignment; the Users list should treat a soft
     * deleted department the same as "unassigned" when rendering.
     */
    public function destroy(Request $request, Department $department): JsonResponse
    {
        $name = $department->name;
        $department->delete();

        AuditLogger::log('department.deleted', "Deleted department \"{$name}\".", $request->user());

        return response()->json([
            'message' => 'Department deleted successfully.',
        ]);
    }

    /**
     * POST /api/admin/departments/{department}/members
     *
     * Assigns users to the department. Admins can move anyone; a department owner may only
     * pick up users who don't belong to a department yet (the same "unassigned" list
     * monday.com shows owners), so they can't pull people out of other departments.
     */
    public function assignMembers(AssignDepartmentMembersRequest $request, Department $department): JsonResponse
    {
        $actor = $request->user();
        abort_unless($department->canBeManagedBy($actor), 403, 'You cannot manage this department.');

        $user_ids = $request->validated('user_ids');
        $users = User::whereIn('id', $user_ids)->get(['id', 'department_id']);

        if (! $actor->hasRole(['super_admin', 'admin'])) {
            $active_department_ids = Department::whereIn('id', $users->pluck('department_id')->filter())->pluck('id');

            $has_taken_user = $users->contains(
                fn (User $user) => $user->department_id !== null
                    && $user->department_id !== $department->id
                    && $active_department_ids->contains($user->department_id)
            );

            abort_if($has_taken_user, 403, 'Department owners can only assign users who have no department yet.');
        }

        User::whereIn('id', $user_ids)->update(['department_id' => $department->id]);
        $this->loadDepartmentAggregates($department);

        AuditLogger::log(
            'department.members_assigned',
            'Assigned '.count($user_ids)." user(s) to department \"{$department->name}\".",
            $actor,
            ['department_id' => $department->id, 'user_ids' => $user_ids],
        );

        return response()->json([
            'message' => 'Members assigned successfully.',
            'department' => new DepartmentResource($department),
        ]);
    }

    /**
     * DELETE /api/admin/departments/{department}/members/{user}
     */
    public function removeMember(Request $request, Department $department, User $user): JsonResponse
    {
        abort_unless($department->canBeManagedBy($request->user()), 403, 'You cannot manage this department.');

        if ($user->department_id === $department->id) {
            $user->update(['department_id' => null]);

            AuditLogger::log(
                'department.member_removed',
                "Removed {$user->full_name} from department \"{$department->name}\".",
                $request->user(),
                ['department_id' => $department->id, 'user_id' => $user->id],
            );
        }

        $this->loadDepartmentAggregates($department);

        return response()->json([
            'message' => 'Member removed successfully.',
            'department' => new DepartmentResource($department),
        ]);
    }

    /**
     * POST /api/admin/departments/{department}/owners (admin only, gated in the route file)
     */
    public function assignOwners(AssignDepartmentOwnersRequest $request, Department $department): JsonResponse
    {
        $user_ids = $request->validated('user_ids');
        $department->owners()->syncWithoutDetaching($user_ids);
        $this->loadDepartmentAggregates($department);

        AuditLogger::log(
            'department.owners_assigned',
            'Assigned '.count($user_ids)." owner(s) to department \"{$department->name}\".",
            $request->user(),
            ['department_id' => $department->id, 'user_ids' => $user_ids],
        );

        return response()->json([
            'message' => 'Owners assigned successfully.',
            'department' => new DepartmentResource($department),
        ]);
    }

    /**
     * DELETE /api/admin/departments/{department}/owners/{user} (admin only, gated in the route file)
     */
    public function removeOwner(Request $request, Department $department, User $user): JsonResponse
    {
        $department->owners()->detach($user->id);
        $this->loadDepartmentAggregates($department);

        AuditLogger::log(
            'department.owner_removed',
            "Removed {$user->full_name} as an owner of department \"{$department->name}\".",
            $request->user(),
            ['department_id' => $department->id, 'user_id' => $user->id],
        );

        return response()->json([
            'message' => 'Owner removed successfully.',
            'department' => new DepartmentResource($department),
        ]);
    }

    /**
     * Loads what {@see DepartmentResource} needs, so every mutation returns the same shape as `index`.
     */
    private function loadDepartmentAggregates(Department $department): void
    {
        $department->loadCount('users');
        $department->load('owners');
    }
}
