<?php

namespace App\Http\Resources;

use App\Models\BoardComment;
use App\Models\BoardItemActivity;
use App\Models\BoardItemComment;
use App\Models\FeedFollow;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Maps a {@see BoardItemComment} or {@see BoardComment} (top-level or reply,
 * both render as feed cards) into the Update Feed DTO. The comment's raw
 * `body` is sent as-is — mention highlighting stays entirely client-side via
 * `renderMentionText()`, the same helper board comment threads already use,
 * so this resource does not re-implement segment parsing.
 *
 * Expects `author`, `mentions.user`, `bookmarks`, `views` and (for
 * `BoardItemComment`) `item.board.parent` — or (for `BoardComment`)
 * `board.parent` — eager-loaded by the caller. Reads directly off the
 * underlying model (rather than through `JsonResource`'s magic `$this->`
 * property forwarding) so the `BoardItemComment|BoardComment` union narrows
 * correctly per branch.
 */
class FeedUpdateResource extends JsonResource
{
    private ?User $viewer = null;

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
                ] : null,
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

        [$board, $item, $link] = $is_item_comment
            ? $this->itemContext($comment)
            : $this->boardContext($comment);

        return [
            'id' => ($is_item_comment ? 'ic-' : 'bc-').$comment->id,
            'actor' => [
                'id' => $comment->author?->id,
                'name' => $comment->author !== null ? $comment->author->full_name : __('Deleted user'),
                'avatar_url' => $comment->author?->profile_photo_url,
            ],
            'body' => $comment->body,
            'created_at' => $comment->created_at,
            'board' => [
                'id' => $board->id,
                'name' => $board->label,
                'parent_name' => $board->parent !== null ? $board->parent->label : null,
            ],
            'item' => $item,
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
                ])
                ->values(),
            'pinned' => $comment->pinned,
            'is_following_item' => $item !== null && in_array($item['id'], $this->followedIds($viewer_id)['items'], true),
            'is_following_board' => in_array($board->id, $this->followedIds($viewer_id)['boards'], true),
            'activity' => $this->activity['entries'] ?? [],
            'activity_total' => $this->activity['total'] ?? 0,
        ];
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
