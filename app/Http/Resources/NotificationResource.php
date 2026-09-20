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
                'is_deactivated' => $this->actor?->is_deactivated ?? true,
            ],
            'action_label' => $this->action_label,
            'action_target' => $this->action_target,
            'board' => $this->board ? [
                'id' => $this->board->id,
                'name' => $this->board->label,
            ] : null,
            'link' => $this->link,
            // The item the notification is about, what "Mute this item" targets.
            'board_item_id' => $this->board_item_id,
            // Only the list endpoint counts mutes (`withExists`), a live push is never for a muted item.
            'is_item_muted' => (bool) ($this->is_item_muted ?? false),
            // The feed id of the comment that triggered it, what an inline reply attaches to.
            'reply_to' => $this->comment_id !== null && $this->comment_kind !== null
                ? ($this->comment_kind === 'item' ? 'ic-' : 'bc-').$this->comment_id
                : null,
            // Notifications of the same type on the same thread share this key,
            // which is how the drawer collapses "3 people replied" into one card.
            'group_key' => $this->link !== null ? "{$this->type}|{$this->link}" : "single|{$this->id}",
            'is_unread' => ! $this->is_read,
            'is_saved' => $this->saved_at !== null,
            'category' => Notification::categoryOf($this->type),
            'created_at' => $this->created_at,
        ];
    }
}
