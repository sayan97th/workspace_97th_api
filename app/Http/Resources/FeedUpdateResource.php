<?php

namespace App\Http\Resources;

use App\Models\BoardComment;
use App\Models\BoardItemComment;
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
 * Expects `author`, `mentions`, `bookmarks`, `views` and (for
 * `BoardItemComment`) `item.board.parent` — or (for `BoardComment`)
 * `board.parent` — eager-loaded by the caller. Reads directly off the
 * underlying model (rather than through `JsonResource`'s magic `$this->`
 * property forwarding) so the `BoardItemComment|BoardComment` union narrows
 * correctly per branch.
 */
class FeedUpdateResource extends JsonResource
{
    private ?User $viewer = null;

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
            'view_count' => $comment->views->count(),
            'is_unread' => $viewer_id !== null
                && $comment->author?->id !== $viewer_id
                && ! $comment->views->contains('user_id', $viewer_id),
            'is_mentioned' => $viewer_id !== null && $comment->mentions->contains('user_id', $viewer_id),
            'is_bookmarked' => $viewer_id !== null && $comment->bookmarks->contains('user_id', $viewer_id),
            'mentioned_user_ids' => $comment->mentions->pluck('user_id')->values(),
        ];
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
            "/boards/{$board->id}/pulses/{$comment->item->id}",
        ];
    }

    /**
     * @return array{0: WorkspaceNavigationItem, 1: null, 2: string}
     */
    private function boardContext(BoardComment $comment): array
    {
        $board = $comment->board;

        return [$board, null, "/boards/{$board->id}"];
    }
}
