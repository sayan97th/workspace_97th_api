<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin User
 */
class UserWithRolesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'profile_photo_url' => $this->profile_photo_url,
            'is_active' => $this->is_active,
            'is_deactivated' => $this->is_deactivated,
            'deleted_at' => $this->deleted_at,
            'email_verified_at' => $this->email_verified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'roles' => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
            ])->values(),
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'job_title' => $this->job_title,
            // Only present on the Administration list, which adds it as a subselect.
            'last_active_at' => $this->when(
                array_key_exists('last_active_at', $this->resource->getAttributes()),
                fn () => $this->resource->getAttribute('last_active_at') ? Carbon::parse($this->resource->getAttribute('last_active_at'))->toIso8601String() : null,
            ),
            // Custom profile field values keyed by field id (Administration > Profile fields).
            'profile_fields' => $this->whenLoaded('profileFieldValues', fn () => (object) $this->profileFieldValues
                ->mapWithKeys(fn ($value) => [(string) $value->field_id => $value->value])
                ->all()),
        ];
    }
}
