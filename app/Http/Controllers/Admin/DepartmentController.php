<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Department\StoreDepartmentRequest;
use App\Http\Requests\Admin\Department\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
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

        $query = Department::withCount('users')->orderBy('name');

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
        $department->loadCount('users');

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
        $department->loadCount('users');

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
}
