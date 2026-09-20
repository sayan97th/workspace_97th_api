<?php

namespace App\Models;

use App\Services\Board\CommentThreadActionsService;
use App\Services\Board\DueDateReminderService;
use App\Services\Notification\NotificationService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An in-app notification delivered to `user_id` (the recipient), triggered by
 * `actor_id`'s action (a mention, a reply, a reaction, an assignment). Created
 * and broadcast exclusively through {@see NotificationService},
 * never directly.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $actor_id
 * @property string $type
 * @property int|null $board_id
 * @property int|null $board_item_id
 * @property string $action_label
 * @property string $action_target
 * @property string|null $link
 * @property bool $is_read
 * @property Carbon|null $read_at
 * @property Carbon|null $dismissed_at
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $saved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read User|null $actor
 * @property-read WorkspaceNavigationItem|null $board
 * @property-read BoardItem|null $boardItem
 */
#[Fillable(['user_id', 'actor_id', 'type', 'board_id', 'board_item_id', 'action_label', 'action_target', 'link', 'is_read', 'read_at', 'dismissed_at', 'snoozed_until', 'saved_at'])]
class Notification extends Model
{
    use HasFactory;

    public const TYPE_MENTIONED = 'mentioned';

    public const TYPE_ASSIGNED = 'assigned';

    public const TYPE_REPLIED_THREAD = 'replied_thread';

    public const TYPE_REPLIED_UPDATE = 'replied_update';

    public const TYPE_REACTIONS = 'reactions';

    /**
     * Sent by the comment composer's "Notify" action — a direct call-out to
     * someone without `@mentioning` them inline, see
     * {@see CommentThreadActionsService::notifyDirect()}.
     */
    public const TYPE_NOTIFIED = 'notified';

    /**
     * Sent by a {@see BoardAutomation}'s `notify_person` action.
     * Matches `App\Enums\NotificationPreferenceKey::AutomationsNotify`'s value
     * exactly (not just prefixed by it), since `NotificationService::notify()`
     * gates delivery by reading `"{$type}_app"`/`"{$type}_email"` straight off
     * the recipient's `notification_preferences`.
     */
    public const TYPE_AUTOMATION = 'automations_notify';

    /**
     * Sent by {@see DueDateReminderService} to every
     * person assigned in a People column on an item whose Date column has a
     * reminder configured and is due today. No `actor_id` (system-generated,
     * like {@see self::TYPE_TEST}).
     */
    public const TYPE_DUE_DATE_REMINDER = 'due_date_reminder';

    /**
     * System-generated notification with no `actor_id`, used to verify the
     * websocket connection is delivering real-time events end to end.
     */
    public const TYPE_TEST = 'test';

    /**
     * The bell drawer's filter tabs and the notification types each one
     * groups. A type missing here (automations, due dates, tests) only shows
     * up under "All", and reports the `subscribed` category.
     *
     * @var array<string, array<int, string>>
     */
    public const CATEGORY_TYPES = [
        'mentioned' => [self::TYPE_MENTIONED],
        'assigned' => [self::TYPE_ASSIGNED, self::TYPE_NOTIFIED],
        'replies' => [self::TYPE_REPLIED_THREAD, self::TYPE_REPLIED_UPDATE],
        'reactions' => [self::TYPE_REACTIONS],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'saved_at' => 'datetime',
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
     * The specific row (item/pulse) this notification is about, if any —
     * currently only set for {@see TYPE_ASSIGNED}, letting the email
     * template resolve the item's table/view/workspace breadcrumb through
     * this relation instead of duplicating those labels as flat columns.
     *
     * @return BelongsTo<BoardItem, $this>
     */
    public function boardItem(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'board_item_id');
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeSaved(Builder $query): Builder
    {
        return $query->whereNotNull('saved_at');
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeNotSaved(Builder $query): Builder
    {
        return $query->whereNull('saved_at');
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    /**
     * Notifications the bell should list: not dismissed, and not snoozed
     * (a snooze that has already elapsed no longer hides the row).
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->whereNull('dismissed_at')
            ->where(fn (Builder $inner) => $inner->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
    }

    /**
     * Keeps only the notification types that belong to a filter tab, see
     * {@see self::CATEGORY_TYPES}. An unknown category leaves the query untouched.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return isset(self::CATEGORY_TYPES[$category])
            ? $query->whereIn('type', self::CATEGORY_TYPES[$category])
            : $query;
    }

    /**
     * The filter tab a stored `type` reports, `subscribed` when it has none.
     */
    public static function categoryOf(string $type): string
    {
        foreach (self::CATEGORY_TYPES as $category => $types) {
            if (in_array($type, $types, true)) {
                return $category;
            }
        }

        return 'subscribed';
    }

    /**
     * How the client should surface this notification live: `is_silenced` while
     * the recipient's quiet hours are active (still listed, no toast or push),
     * `is_push_muted` when they turned off the desktop push for this type.
     * Shared by the websocket payload and the polling fallback so both channels
     * always agree.
     *
     * @return array{is_silenced: bool, is_push_muted: bool}
     */
    public function deliveryFlags(?User $recipient = null): array
    {
        $recipient ??= $this->user;

        return [
            'is_silenced' => $recipient->isInQuietHours(),
            'is_push_muted' => (($recipient->notification_preferences ?? [])["{$this->type}_push"] ?? true) === false,
        ];
    }

    public function markAsRead(): void
    {
        $this->update(['is_read' => true, 'read_at' => now()]);
    }

    public function markAsUnread(): void
    {
        $this->update(['is_read' => false, 'read_at' => null]);
    }

    /**
     * Hides the notification until `$until`, when `notifications:wake-snoozed`
     * brings it back as unread.
     */
    public function snoozeUntil(Carbon $until): void
    {
        $this->update(['snoozed_until' => $until]);
    }
}
