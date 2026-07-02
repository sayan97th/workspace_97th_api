<?php

namespace App\Http\Controllers\Admin\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\UpdateUserRequest;
use App\Http\Resources\UserWithRolesResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    private const STAFF_ROLES = ['super_admin', 'admin', 'staff'];

    private const ALLOWED_SORT_FIELDS = ['name', 'email', 'created_at'];

    /**
     * GET /api/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $search = $request->query('search');
        $role = $request->query('role');
        $sort_field = $request->query('sort_field', 'created_at');
        $sort_direction = $request->query('sort_direction', 'desc');
        $email_status = $request->query('email_status');
        $account_status = $request->query('account_status');

        if ($type !== null && ! \in_array($type, ['staff', 'client'], true)) {
            return response()->json([
                'message' => 'The type field must be staff or client.',
                'errors' => ['type' => ['The selected type is invalid.']],
            ], 422);
        }

        if (! \in_array($sort_field, self::ALLOWED_SORT_FIELDS, true)) {
            return response()->json([
                'message' => 'The sort_field value is invalid.',
                'errors' => ['sort_field' => ['The selected sort field is invalid.']],
            ], 422);
        }

        if (! \in_array($sort_direction, ['asc', 'desc'], true)) {
            $sort_direction = 'asc';
        }

        if ($role !== null && ! \in_array($role, self::STAFF_ROLES, true) && $role !== 'client') {
            $role = null;
        }

        $query = User::with('roles:id,name,display_name')
            ->orderBy($sort_field, $sort_direction);

        if ($type === 'staff') {
            $query->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES));
        } elseif ($type === 'client') {
            $query->whereHas('roles', fn ($q) => $q->where('name', 'client'))
                ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES));
        }

        if ($role !== null) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        if ($email_status === 'verified') {
            $query->whereNotNull('email_verified_at');
        } elseif ($email_status === 'unverified') {
            $query->whereNull('email_verified_at');
        }

        if ($account_status === 'active') {
            $query->where('is_active', true);
        } elseif ($account_status === 'disabled') {
            $query->where('is_active', false);
        }

        $per_page = min((int) $request->query('per_page', 15), 500);
        $users = $query->paginate($per_page);

        return response()->json([
            'data' => UserWithRolesResource::collection($users->items()),
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'total' => $users->total(),
        ]);
    }

    /**
     * GET /api/admin/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        $user->load('roles:id,name,display_name');

        return response()->json(new UserWithRolesResource($user));
    }

    /**
     * PATCH /api/admin/users/{user}
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user->update($request->validated());

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => new UserWithRolesResource($user->fresh('roles:id,name,display_name')),
        ]);
    }

    /**
     * PATCH /api/admin/users/{user}/ban
     */
    public function ban(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($actor->id === $user->id) {
            return response()->json(['message' => 'You cannot disable your own account.'], 422);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This account is already disabled.'], 409);
        }

        if (! $this->actorCanManage($actor, $user)) {
            return response()->json(['message' => 'You do not have permission to disable this account.'], 403);
        }

        $user->update(['is_active' => false]);

        return response()->json([
            'message' => 'User account has been disabled.',
            'user' => new UserWithRolesResource($user->fresh('roles:id,name,display_name')),
        ]);
    }

    /**
     * PATCH /api/admin/users/{user}/unban
     */
    public function unban(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($user->is_active) {
            return response()->json(['message' => 'This account is already active.'], 409);
        }

        if (! $this->actorCanManage($actor, $user)) {
            return response()->json(['message' => 'You do not have permission to re-enable this account.'], 403);
        }

        $user->update(['is_active' => true]);

        return response()->json([
            'message' => 'User account has been re-enabled.',
            'user' => new UserWithRolesResource($user->fresh('roles:id,name,display_name')),
        ]);
    }

    /**
     * Determine if the acting user is allowed to change the target user's active status.
     *
     * Super admins can manage anyone; plain admins may only manage client-only accounts.
     */
    private function actorCanManage(User $actor, User $target): bool
    {
        if ($actor->hasRole('super_admin')) {
            return true;
        }

        $target_roles = $target->roles->pluck('name');

        return $target_roles->intersect(self::STAFF_ROLES)->isEmpty();
    }
}
