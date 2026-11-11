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
        $is_from_automations = $this->sender_type === Notification::SENDER_AUTOMATIONS;
        $shows_message_only = $is_from_automations && $this->comment_id === null && $this->preview !== null;

        return [
            'id' => (string) $this->id,
            'type' => $this->type,
            // `actor`/`board` are always eager-loaded by both callers (the
            // controller's index() and NotificationService::notify()), so we
            // read them directly rather than through whenLoaded(): that helper
            // returns null outright for a loaded-but-null relation without ever
            // calling the fallback closure, which silently dropped the
            // "Deleted user" fallback below and sent `actor.name` as null.
            // monday's sender: "Automations" with the app logo for what an
            // automation or reminder sent, otherwise the person behind it.
            'actor' => $is_from_automations ? [
                'name' => 'Automations',
                'id' => null,
                'avatar_url' => null,
                'is_deactivated' => false,
                'is_system' => true,
            ] : [
                'name' => $this->actor?->full_name ?? 'Deleted user',
                'id' => $this->actor?->id,
                'avatar_url' => $this->actor?->profile_photo_url,
                'is_deactivated' => $this->actor?->is_deactivated ?? true,
                'is_system' => false,
            ],
            'sender_type' => $this->sender_type,
            // An automation message replaces the sentence in the bell, like
            // monday: "Automations" over the message, nothing in between.
            'action_label' => $shows_message_only ? '' : $this->action_label,
            'action_target' => $shows_message_only ? '' : $this->action_target,
            // The live board while it exists, its stored name once it is deleted.
            'board' => $this->board ? [
                'id' => $this->board->id,
                'name' => $this->board->label,
            ] : ($this->board_name !== null ? ['id' => null, 'name' => $this->board_name] : null),
            'link' => $this->link,
            // monday's notification target, `post` (an update or reply) or `project` (an item or board).
            'target' => $this->target_type !== null ? ['type' => $this->target_type, 'id' => $this->target_id] : null,
            // Snapshots stored with the row, see Notification::snapshotAttributes().
            'item_name' => $this->item_name,
            // Plain text excerpt of the comment behind it (mentions, replies,
            // reactions, notify), what the card shows under the sentence.
            'preview' => $this->preview,
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
            // Only the list endpoint adds it (`withViewerLike`), a live push has not been liked yet.
            'is_liked' => (bool) ($this->is_liked ?? false),
            'is_unread' => ! $this->is_read,
            'is_saved' => $this->saved_at !== null,
            'category' => Notification::categoryOf($this->type),
            'created_at' => $this->created_at,
        ];
    }
}
