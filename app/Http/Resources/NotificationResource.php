<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type,
            // `actor`/`board` are always eager-loaded by both callers (the
            // controller's index() and NotificationService::notify()), so we
            // read them directly rather than through whenLoaded(): that helper
            // returns null outright for a loaded-but-null relation without ever
            // calling the fallback closure, which silently dropped the
            // "Deleted user" fallback below and sent `actor.name` as null.
            'actor' => [
                'name' => $this->actor?->full_name ?? 'Deleted user',
                'id' => $this->actor?->id,
                'avatar_url' => $this->actor?->profile_photo_url,
            ],
            'action_label' => $this->action_label,
            'action_target' => $this->action_target,
            'board' => $this->board ? [
                'id' => $this->board->id,
                'name' => $this->board->label,
            ] : null,
            'link' => $this->link,
            // Notifications of the same type on the same thread share this key,
            // which is how the drawer collapses "3 people replied" into one card.
            'group_key' => $this->link !== null ? "{$this->type}|{$this->link}" : "single|{$this->id}",
            'is_unread' => ! $this->is_read,
            'category' => Notification::categoryOf($this->type),
            'created_at' => $this->created_at,
        ];
    }
}
