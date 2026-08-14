<?php

namespace App\Http\Resources;

use App\Concerns\IssuesJwtTokens;
use App\Http\Controllers\Profile\ProfileController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated user's own profile — returned by `GET/PUT/PATCH /api/profile` and
 * every JWT-issuing auth endpoint (login, register, 2FA challenge, refresh, `/auth/me`).
 * Single source of truth for that payload shape, replacing the two previously-duplicated
 * `formatUser()` hand-rolled arrays in {@see ProfileController}
 * and {@see IssuesJwtTokens}.
 *
 * @mixin User
 */
class ProfileResource extends JsonResource
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
            'timezone' => $this->timezone,
            'profile_photo_url' => $this->profile_photo_url,
            'email_verified_at' => $this->email_verified_at,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'working_status' => $this->working_status,
            'working_status_dates' => $this->working_status_dates,
            'disable_notifications_while_away' => $this->disable_notifications_while_away,
            'hide_online_status' => $this->hide_online_status,

            'notification_preferences' => $this->notification_preferences ?? [],
            'desktop_notifications_enabled' => $this->desktop_notifications_enabled,

            'language' => $this->language,
            'time_format' => $this->time_format,
            'date_format' => $this->date_format,
            'first_day_of_week' => $this->first_day_of_week,

            'roles' => $this->when(
                $this->relationLoaded('roles'),
                fn () => $this->roles->map(fn ($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                ])->values(),
            ),
        ];
    }
}
