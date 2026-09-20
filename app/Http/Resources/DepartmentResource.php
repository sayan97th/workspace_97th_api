<?php

namespace App\Http\Resources;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the Departments table. `assigned`/`available` are query-time aggregates set by
 * the controller via `setAttribute()` before wrapping (the controller must eager-load
 * `users_count`, e.g. `withCount('users')`), the same idiom `WorkspaceController` uses for
 * `membership_role`. The controller must also eager-load `owners` so the per-row permission
 * flags below never trigger an N+1.
 *
 * @mixin Department
 */
class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assigned = (int) ($this->users_count ?? 0);
        $seat_limit = $this->seat_limit;
        $viewer = $request->user();
        $is_admin = $viewer !== null && $viewer->hasRole(['super_admin', 'admin']);
        $is_owner = $viewer !== null && $this->owners->contains('id', $viewer->id);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'seat_limit' => $seat_limit,
            'reserved' => $seat_limit,
            'assigned' => $assigned,
            'available' => $seat_limit !== null ? max($seat_limit - $assigned, 0) : null,
            'over_by' => $seat_limit !== null ? max($assigned - $seat_limit, 0) : 0,
            'owners' => $this->owners->map(fn ($owner) => [
                'id' => $owner->id,
                'full_name' => $owner->full_name,
                'email' => $owner->email,
                'profile_photo_url' => $owner->profile_photo_url,
            ])->values(),
            'can_administer' => $is_admin,
            'can_manage_members' => $is_admin || $is_owner,
            'created_at' => $this->created_at,
        ];
    }
}
