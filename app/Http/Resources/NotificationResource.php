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
     * Maps a stored notification `type` to the tab category the frontend's
     * `NotificationsPanel` already understands ("mentioned" | "assigned" |
     * "subscribed"). Reply/reaction types fall under "subscribed" so they
     * only show up in "All", not a dedicated tab.
     *
     * @var array<string, string>
     */
    private const CATEGORY_MAP = [
        Notification::TYPE_MENTIONED => 'mentioned',
        Notification::TYPE_ASSIGNED => 'assigned',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'actor' => [
                'name' => $this->whenLoaded('actor', fn () => $this->actor?->full_name ?? 'Deleted user', 'Deleted user'),
                'id' => $this->whenLoaded('actor', fn () => $this->actor?->id, null),
            ],
            'action_label' => $this->action_label,
            'action_target' => $this->action_target,
            'board' => $this->whenLoaded('board', fn () => $this->board ? [
                'id' => $this->board->id,
                'name' => $this->board->label,
            ] : null),
            'link' => $this->link,
            'is_unread' => ! $this->is_read,
            'category' => self::CATEGORY_MAP[$this->type] ?? 'subscribed',
            'created_at' => $this->created_at,
        ];
    }
}
