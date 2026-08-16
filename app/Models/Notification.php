<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An in-app notification delivered to `user_id` (the recipient), triggered by
 * `actor_id`'s action (a mention, a reply, a reaction, an assignment). Created
 * and broadcast exclusively through {@see \App\Services\Notification\NotificationService},
 * never directly.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $actor_id
 * @property string $type
 * @property int|null $board_id
 * @property string $action_label
 * @property string $action_target
 * @property string|null $link
 * @property bool $is_read
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read User|null $actor
 * @property-read WorkspaceNavigationItem|null $board
 */
#[Fillable(['user_id', 'actor_id', 'type', 'board_id', 'action_label', 'action_target', 'link', 'is_read', 'read_at'])]
class Notification extends Model
{
    use HasFactory;

    public const TYPE_MENTIONED = 'mentioned';

    public const TYPE_ASSIGNED = 'assigned';

    public const TYPE_REPLIED_THREAD = 'replied_thread';

    public const TYPE_REPLIED_UPDATE = 'replied_update';

    public const TYPE_REACTIONS = 'reactions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    /**
     * The recipient of this notification.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user whose action triggered this notification.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The board this notification is scoped to, if any.
     *
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    public function markAsRead(): void
    {
        $this->update(['is_read' => true, 'read_at' => now()]);
    }
}
