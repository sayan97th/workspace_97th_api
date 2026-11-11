<?php

namespace App\Http\Resources;

use App\Models\BoardComment;
use App\Models\BoardItemActivity;
use App\Models\BoardItemComment;
use App\Models\FeedFollow;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Maps a {@see BoardItemComment} or {@see BoardComment} into the Update Feed
 * DTO. The feed lists threads the way monday.com does: one card per top-level
 * update with its replies inline under it (see {@see withReplies()}), so a
 * reply never shows up as a card of its own. The comment's raw
 * `body` is sent as-is — mention highlighting stays entirely client-side via
 * `renderMentionText()`, the same helper board comment threads already use,
 * so this resource does not re-implement segment parsing.
 *
 * Expects `author`, `mentions.user`, `bookmarks`, `views`, `likes.user`,
 * `reactions.user` and (for
 * `BoardItemComment`) `item.board.parent` — or (for `BoardComment`)
 * `board.parent` — eager-loaded by the caller. Reads directly off the
 * underlying model (rather than through `JsonResource`'s magic `$this->`
 * property forwarding) so the `BoardItemComment|BoardComment` union narrows
 * correctly per branch.
 */
class FeedUpdateResource extends JsonResource
{
    /** What a payload reads off an item update, eager loaded by every caller. */
    public const ITEM_LOADS = ['author', 'mentions.user', 'bookmarks', 'views', 'likes.user', 'reactions.user', 'item.board.parent', 'item.group'];

    /** What a payload reads off a board discussion update. */
    public const BOARD_LOADS = ['author', 'mentions.user', 'bookmarks', 'views', 'likes.user', 'reactions.user', 'board.parent'];

    private ?User $viewer = null;

    /** @var Collection<int, BoardItemComment|BoardComment>|null The thread's replies, oldest first, set on a top-level update only. */
    private ?Collection $replies = null;

    /** @var array{items: array<int, int>, boards: array<int, int>}|null Ids the viewer follows, injected by the caller to avoid one query per card. */
    private ?array $follows = null;

    /** @var array{total: int, entries: array<int, array<string, mixed>>}|null */
    private ?array $activity = null;

    /** Typed mirror of {@see JsonResource::$resource} — that property is untyped, so every read here goes through this one instead. */
    private readonly BoardItemComment|BoardComment $comment;

    public function __construct(BoardItemComment|BoardComment $comment)
    {
        parent::__construct($comment);

        $this->comment = $comment;
    }

    /**
     * Overrides the viewer used to resolve `is_unread`/`is_mentioned`/
     * `is_bookmarked`, for contexts with no request (a queued broadcast) or
     * where the payload is built for someone other than the requester.
     */
    public function forViewer(User $viewer): self
    {
        $this->viewer = $viewer;

        return $this;
    }

    /**
     * Supplies the board and item ids the viewer follows, so `is_following_*`
     * need no query of their own. Without it they are looked up per card.
     *
     * @param  array<int, int>  $item_ids
     * @param  array<int, int>  $board_ids
     */
    public function withFollows(array $item_ids, array $board_ids): self
    {
        $this->follows = ['items' => $item_ids, 'boards' => $board_ids];

        return $this;
    }

    /**
     * Supplies the item changes shown under a top-level item update, see
     * {@see activityPayload()}.
     *
     * @param  array{total: int, entries: array<int, array<string, mixed>>}  $activity
     */
    public function withActivity(array $activity): self
    {
        $this->activity = $activity;

        return $this;
    }

    /**
     * Sends the thread's replies inline under this top-level update, oldest
     * first, each in the same shape as a card.
     *
     * @param  iterable<int, BoardItemComment|BoardComment>  $replies  A plain or Eloquent collection, or any list of replies.
     */
    public function withReplies(iterable $replies): self
    {
        $this->replies = collect($replies)->values();

        return $this;
    }

    /**
     * The eager loads a payload of `$comment`'s kind reads.
     *
     * @return array<int, string>
     */
    public static function loadsFor(BoardItemComment|BoardComment $comment): array
    {
        return $comment instanceof BoardItemComment ? self::ITEM_LOADS : self::BOARD_LOADS;
    }

    /**
     * Eager loads every root's visible replies, oldest first, with what a
     * reply payload reads, one query per comment table.
     *
     * @param  Collection<int, BoardItemComment|BoardComment>  $roots
     */
    public static function loadReplies(Collection $roots): void
    {
        foreach ($roots->groupBy(fn ($comment) => $comment::class) as $group) {
            $loads = self::loadsFor($group->first());

            (new EloquentCollection($group->all()))->load(['replies' => fn ($query) => $query->with($loads)]);
        }
    }

    /**
     * The card a comment belongs to in the feed: the comment itself when it is
     * a top-level update, its parent when it is a reply. A reply whose parent
     * is gone stands on its own.
     */
    public static function threadRoot(BoardItemComment|BoardComment $comment): BoardItemComment|BoardComment
    {
        if ($comment->parent_id === null) {
            return $comment;
        }

        $parent = $comment::query()->visibleNow()->with(self::loadsFor($comment))->find($comment->parent_id);

        return $parent instanceof $comment ? $parent : $comment;
    }

    /**
     * A ready to send card for `$comment`'s whole thread (the root with every
     * reply), as seen by `$viewer`. Used by the live broadcast, so a new reply
     * lands inside its thread instead of as a card of its own.
     *
     * @return array<string, mixed>
     */
    public static function threadPayload(BoardItemComment|BoardComment $comment, User $viewer): array
    {
        $root = self::threadRoot($comment);
        self::loadReplies(collect([$root]));

        return (new self($root))->forViewer($viewer)->withReplies($root->replies)->resolve();
    }

    /**
     * The JSON shape of the item changes behind one update.
     *
     * @param  array{total: int, entries: iterable<int, BoardItemActivity>}  $bundle
     * @return array{total: int, entries: array<int, array<string, mixed>>}
     */
    public static function activityPayload(array $bundle): array
    {
        $entries = [];
        foreach ($bundle['entries'] as $entry) {
            $entries[] = [
                'id' => $entry->id,
                'actor' => $entry->user !== null ? [
                    'id' => $entry->user->id,
                    'name' => $entry->user->full_name,
                    'avatar_url' => $entry->user->profile_photo_url,
                    'is_deactivated' => $entry->user->is_deactivated,
                ] : null,
                // Set when an automation made the change, the feed then credits it instead of the actor.
                'automation' => $entry->automation_name !== null ? ['id' => $entry->automation_id, 'name' => $entry->automation_name] : null,
                'column_label' => $entry->column_label,
                'column_type' => $entry->column_type,
                'old_display' => $entry->old_display,
                'new_display' => $entry->new_display,
                'created_at' => $entry->created_at,
            ];
        }

        return ['total' => $bundle['total'], 'entries' => $entries];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $comment = $this->comment;
        $viewer_id = $this->viewer !== null ? $this->viewer->id : $request->user()?->id;
        $is_item_comment = $comment instanceof BoardItemComment;
        $prefix = $is_item_comment ? 'ic-' : 'bc-';
        // A broadcast payload is built from a comment loaded elsewhere, so fill in what the like row needs.
        $comment->loadMissing(['likes.user', 'reactions.user']);

        [$board, $item, $link] = $is_item_comment
            ? $this->itemContext($comment)
            : $this->boardContext($comment);

        return [
            'id' => $prefix.$comment->id,
            // The card this update shows in: its own id for a top-level update, its parent's for a reply.
            'thread_id' => $prefix.($comment->parent_id ?? $comment->id),
            // monday's `creator`: "Automations" with the app logo for what an automation posted, otherwise the author.
            'actor' => $comment->isFromAutomations() ? [
                'id' => null,
                'name' => 'Automations',
                'avatar_url' => null,
                'is_deactivated' => false,
                'is_system' => true,
            ] : [
                'id' => $comment->author?->id,
                'name' => $comment->author !== null ? $comment->author->full_name : __('Deleted user'),
                'avatar_url' => $comment->author?->profile_photo_url,
                'is_deactivated' => $comment->author?->is_deactivated ?? true,
                'is_system' => false,
            ],
            'sender_type' => $comment->sender_type ?? 'user',
            'automation_id' => $comment->automation_id,
            'body' => $comment->body,
            // monday's `text_body`, the plain text twin of `body`.
            'text_body' => $comment->text_body,
            'created_at' => $comment->created_at,
            'edited_at' => $comment->edited_at,
            'board' => [
                'id' => $board->id,
                'name' => $board->label,
                'parent_name' => $board->parent !== null ? $board->parent->label : null,
            ],
            'item' => $item,
            // The group the item sits in, the middle crumb of monday's board > group > item trail.
            'group' => $is_item_comment && $comment->item->group !== null
                ? ['id' => $comment->item->group->id, 'name' => $comment->item->group->name]
                : null,
            'link' => $link,
            // A reply to another update rather than a top-level one, for the feed's "Replies" filter.
            'is_reply' => $comment->parent_id !== null,
            'view_count' => $comment->views->count(),
            'is_unread' => $viewer_id !== null
                && $comment->author?->id !== $viewer_id
                && ! $comment->views->contains('user_id', $viewer_id),
            'is_mentioned' => $viewer_id !== null && $comment->mentions->contains('user_id', $viewer_id),
            'is_bookmarked' => $viewer_id !== null && $comment->bookmarks->contains('user_id', $viewer_id),
            'mentioned_user_ids' => $comment->mentions->pluck('user_id')->values(),
            // Names for the hover cards over each `@mention` in the body.
            'mentions' => $comment->mentions
                ->filter(fn ($mention) => $mention->user !== null)
                ->map(fn ($mention) => [
                    'id' => $mention->user_id,
                    'name' => $mention->user->full_name,
                    'avatar_url' => $mention->user->profile_photo_url,
                    'is_deactivated' => $mention->user->is_deactivated,
                ])
                ->values(),
            'pinned' => $comment->pinned,
            'like_count' => $comment->likes->count(),
            'liked_by_me' => $viewer_id !== null && $comment->likes->contains('user_id', $viewer_id),
            // Who liked and when, oldest first, the same shape the item drawer's Like card reads.
            'likers' => $comment->likes
                ->sortBy('created_at')
                ->map(fn ($like) => [
                    'id' => $like->user_id,
                    'full_name' => $like->user?->full_name ?? __('Deleted user'),
                    'profile_photo_url' => $like->user?->profile_photo_url,
                    'is_deactivated' => $like->user?->is_deactivated ?? true,
                    'liked_at' => $like->created_at,
                ])
                ->values(),
            'reactions' => $this->reactionsPayload($viewer_id),
            'is_following_item' => $item !== null && in_array($item['id'], $this->followedIds($viewer_id)['items'], true),
            'is_following_board' => in_array($board->id, $this->followedIds($viewer_id)['boards'], true),
            'activity' => $this->activity['entries'] ?? [],
            'activity_total' => $this->activity['total'] ?? 0,
            'replies' => $this->repliesPayload($request),
        ];
    }

    /**
     * The thread's replies in the card shape, read by the same viewer and with
     * the same follows as the card itself. Empty for a reply, or a card built
     * without {@see withReplies()}.
     *
     * @return array<int, array<string, mixed>>
     */
    private function repliesPayload(Request $request): array
    {
        if ($this->replies === null) {
            return [];
        }

        $follows = $this->followedIds($this->viewer !== null ? $this->viewer->id : $request->user()?->id);

        return $this->replies
            ->map(function (BoardItemComment|BoardComment $reply) use ($request, $follows) {
                $resource = (new self($reply))->withFollows($follows['items'], $follows['boards']);

                return ($this->viewer !== null ? $resource->forViewer($this->viewer) : $resource)->toArray($request);
            })
            ->values()
            ->all();
    }

    /**
     * Emoji reactions grouped by emoji in the order they were first used, with
     * who reacted and when, matching `BoardItemCommentResource::reactions`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reactionsPayload(?int $viewer_id): array
    {
        return $this->comment->reactions
            ->sortBy('created_at')
            ->groupBy('emoji')
            ->map(fn ($group, $emoji) => [
                'emoji' => (string) $emoji,
                'count' => $group->count(),
                'reacted_by_me' => $viewer_id !== null && $group->contains('user_id', $viewer_id),
                'reactors' => $group
                    ->map(fn ($reaction) => [
                        'id' => $reaction->user_id,
                        'full_name' => $reaction->user?->full_name ?? __('Deleted user'),
                        'profile_photo_url' => $reaction->user?->profile_photo_url,
                        'is_deactivated' => $reaction->user?->is_deactivated ?? true,
                        'reacted_at' => $reaction->created_at,
                    ])
                    ->values(),
                'reactor_names' => $group
                    ->map(fn ($reaction) => $reaction->user_id === $viewer_id
                        ? __('You')
                        : ($reaction->user?->full_name ?? __('Deleted user')))
                    ->values(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{items: array<int, int>, boards: array<int, int>}
     */
    private function followedIds(?int $viewer_id): array
    {
        if ($this->follows === null) {
            $rows = $viewer_id === null ? collect() : FeedFollow::where('user_id', $viewer_id)->get(['target_type', 'target_id']);

            $this->follows = [
                'items' => $rows->where('target_type', FeedFollow::TYPE_ITEM)->pluck('target_id')->map(fn ($id) => (int) $id)->values()->all(),
                'boards' => $rows->where('target_type', FeedFollow::TYPE_BOARD)->pluck('target_id')->map(fn ($id) => (int) $id)->values()->all(),
            ];
        }

        return $this->follows;
    }

    /**
     * @return array{0: WorkspaceNavigationItem, 1: array{id: int, name: string}, 2: string}
     */
    private function itemContext(BoardItemComment $comment): array
    {
        $board = $comment->item->board;

        return [
            $board,
            ['id' => $comment->item->id, 'name' => $comment->item->name],
            "/boards/{$board->id}/pulses/{$comment->item->id}?comment={$comment->id}",
        ];
    }

    /**
     * @return array{0: WorkspaceNavigationItem, 1: null, 2: string}
     */
    private function boardContext(BoardComment $comment): array
    {
        $board = $comment->board;

        return [$board, null, "/boards/{$board->id}?update={$comment->id}"];
    }
}
