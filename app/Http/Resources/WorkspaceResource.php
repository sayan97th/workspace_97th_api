<?php

namespace App\Http\Resources;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workspace
 *
 * Membership metadata for the current user is provided by the controller as the
 * transient attributes `membership_role` and `membership_is_recent` (they are not
 * real columns), so a single query over all workspaces can still expose the
 * current user's relationship to each one.
 */
class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->resource->getAttribute('membership_role');
        $is_recent = (bool) $this->resource->getAttribute('membership_is_recent');

        $memberships = [];
        if (is_string($role) && $role !== '') {
            $memberships[] = $role;
        }
        if ($is_recent) {
            $memberships[] = 'recent';
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'mono' => $this->mono,
            'color' => $this->color,
            'product' => $this->product,
            'is_home' => $this->is_home,
            'description' => $this->description,
            'position' => $this->position,
            'role' => (is_string($role) && $role !== '') ? ucfirst($role) : null,
            'memberships' => $memberships,
        ];
    }
}
