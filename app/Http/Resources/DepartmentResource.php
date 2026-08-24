<?php

namespace App\Http\Resources;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the Departments table. `assigned`/`available` are query-time aggregates set by
 * the controller via `setAttribute()` before wrapping (the controller must eager-load
 * `users_count`, e.g. `withCount('users')`), the same idiom `WorkspaceController` uses for
 * `membership_role`.
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

        return [
            'id' => $this->id,
            'name' => $this->name,
            'seat_limit' => $seat_limit,
            'reserved' => $seat_limit,
            'assigned' => $assigned,
            'available' => $seat_limit !== null ? max($seat_limit - $assigned, 0) : null,
            'created_at' => $this->created_at,
        ];
    }
}
