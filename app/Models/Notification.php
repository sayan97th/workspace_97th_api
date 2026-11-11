<?php

namespace App\Models;

use App\Services\Board\CommentThreadActionsService;
use App\Services\Board\DueDateReminderService;
use App\Services\Notification\NotificationService;
use App\Services\Search\Everything\PlainText;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An in-app notification delivered to `user_id` (the recipient), triggered by
 * `actor_id`'s action (a mention, a reply, a reaction, an assignment). Created
 * and broadcast exclusively through {@see NotificationService},
 * never directly.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $actor_id
 * @property string $sender_type
 * @property string $type
 * @property int|null $board_id
 * @property int|null $board_item_id
 * @property int|null $comment_id
 * @property string|null $comment_kind
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $preview
 * @property string|null $item_name
 * @property string|null $board_name
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
#[Fillable(['user_id', 'actor_id', 'sender_type', 'type', 'board_id', 'board_item_id', 'comment_id', 'comment_kind', 'target_type', 'target_id', 'action_label', 'action_target', 'preview', 'item_name', 'board_name', 'link', 'is_read', 'read_at', 'dismissed_at', 'snoozed_until', 'saved_at'])]
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

    /** Longest message preview stored on a notification, the card clamps it to two lines with CSS. */
    public const PREVIEW_MAX_LENGTH = 280;

    /**
     * monday.com's two notification targets (`create_notification`'s
     * `target_type`): a `post` is an update or a reply, `target_id` is the
     * comment, a `project` is an item or a board, `target_id` is that item or
     * board.
     */
    public const TARGET_POST = 'post';

    public const TARGET_PROJECT = 'project';

    /** The bell shows the person in `actor_id` as the sender. */
    public const SENDER_USER = 'user';

    /**
     * The bell shows "Automations" with the app logo as the sender, monday's
     * creator for what an automation or a reminder sent on someone's behalf.
     */
    public const SENDER_AUTOMATIONS = 'automations';

    /** Types an automation or a reminder sends, see {@see self::senderTypeOf()}. */
    private const AUTOMATION_TYPES = [self::TYPE_AUTOMATION, self::TYPE_DUE_DATE_REMINDER];

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
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
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
     * The snapshot columns a new notification is stored with, so the row alone
     * holds everything the bell card shows, like a monday.com notification: its
     * target (the comment when there is one, otherwise the item, otherwise the
     * board), the plain text preview of the message and the item and board
     * names at the time it was sent.
     *
     * `$message` is a message written for the notification itself (an
     * automation's "notify" text), stored as the preview when there is no comment.
     *
     * @return array{target_type: string|null, target_id: int|null, preview: string|null, item_name: string|null, board_name: string|null}
     */
    public static function snapshotAttributes(
        ?WorkspaceNavigationItem $board,
        ?BoardItem $board_item,
        BoardItemComment|BoardComment|null $comment,
        ?string $message = null,
    ): array {
        [$target_type, $target_id] = match (true) {
            $comment !== null => [self::TARGET_POST, $comment->id],
            $board_item !== null => [self::TARGET_PROJECT, $board_item->id],
            $board !== null => [self::TARGET_PROJECT, $board->id],
            default => [null, null],
        };

        return [
            'target_type' => $target_type,
            'target_id' => $target_id,
            'preview' => self::previewFrom($comment?->body ?? $message),
            'item_name' => $board_item?->name,
            'board_name' => $board?->label,
        ];
    }

    /**
     * Who the bell shows as the sender of a notification of `$type`.
     */
    public static function senderTypeOf(string $type): string
    {
        return in_array($type, self::AUTOMATION_TYPES, true) ? self::SENDER_AUTOMATIONS : self::SENDER_USER;
    }

    /**
     * Plain text excerpt of a Markdown message body, null when nothing readable is left.
     */
    public static function previewFrom(?string $body): ?string
    {
        $text = PlainText::fromMarkdown($body);

        return $text === '' ? null : Str::limit($text, self::PREVIEW_MAX_LENGTH);
    }

    /**
     * Notifications sent about `$comment`, which `comment_id` alone cannot tell
     * apart since item and board comments live in separate tables.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeForComment(Builder $query, BoardItemComment|BoardComment $comment): Builder
    {
        return $query
            ->where('comment_kind', $comment instanceof BoardItemComment ? 'item' : 'board')
            ->where('comment_id', $comment->id);
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
