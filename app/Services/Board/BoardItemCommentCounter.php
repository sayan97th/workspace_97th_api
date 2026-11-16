<?php

namespace App\Services\Board;

use App\Events\BoardItemCommentsChanged;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardItemCommentMention;
use App\Models\WorkspaceNavigationItem;
use Throwable;

/**
 * The single source of a row's updates counter on the board (the chat bubble next to the item
 * name): how many published updates and replies an item has, and who is tagged in them.
 *
 * Every place that adds or removes an update calls {@see changed()}, which tells every open view
 * of the board right away through {@see BoardItemCommentsChanged}, so nobody has to refresh the
 * page to see the new count. {@see boardSnapshot()} is the catch up read a view does after its
 * socket was down.
 */
class BoardItemCommentCounter
{
    public const ACTION_POSTED = 'posted';

    public const ACTION_DELETED = 'deleted';

    public const ACTION_RESTORED = 'restored';

    /**
     * The item's current counter, what {@see BoardItemCommentsChanged} sends and the comment
     * endpoints return as `item_comments`.
     *
     * @return array{item_id: int, comment_count: int, mentioned_user_ids: array<int, int>, changed_at: int}
     */
    public function summaryFor(BoardItem $board_item): array
    {
        return [
            'item_id' => $board_item->id,
            'comment_count' => $board_item->comments()->visibleNow()->count(),
            'mentioned_user_ids' => BoardItemCommentMention::query()
                ->whereIn('comment_id', $board_item->comments()->visibleNow()->select('id'))
                ->distinct()
                ->orderBy('user_id')
                ->pluck('user_id')
                ->map(fn ($user_id) => (int) $user_id)
                ->all(),
            'changed_at' => $this->stamp(),
        ];
    }

    /**
     * Sends the item's new counter to everyone viewing its board and returns it, so the caller
     * can put it in its own response as well. The socket is a nicety: a Reverb outage is
     * reported, never turned into a failed request.
     *
     * @return array{item_id: int, comment_count: int, mentioned_user_ids: array<int, int>, changed_at: int}
     */
    public function changed(BoardItem $board_item, string $action, ?int $actor_id, ?BoardItemComment $comment = null): array
    {
        $summary = $this->summaryFor($board_item);

        try {
            broadcast(new BoardItemCommentsChanged(
                board_id: (int) $board_item->board_id,
                summary: $summary,
                action: $action,
                actor_id: $actor_id,
                comment_id: $comment?->id,
                parent_id: $comment?->parent_id,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }

        return $summary;
    }

    /**
     * Every item of the board that has published updates, with its count, plus the ids of the
     * items where `$viewer_id` is tagged. Items left out have no updates. Archived items are
     * included on purpose, the client simply ignores ids it has not loaded.
     *
     * @return array{counts: array<string, int>, mentioned_item_ids: array<int, int>, changed_at: int}
     */
    public function boardSnapshot(WorkspaceNavigationItem $board, int $viewer_id): array
    {
        $changed_at = $this->stamp();
        $item_ids = BoardItem::query()->where('board_id', $board->id)->select('id');

        $counts = BoardItemComment::query()
            ->visibleNow()
            ->whereIn('item_id', $item_ids)
            ->groupBy('item_id')
            ->selectRaw('item_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'item_id')
            ->mapWithKeys(fn ($count, $item_id) => [(string) $item_id => (int) $count])
            ->all();

        $mentioned_item_ids = BoardItemComment::query()
            ->visibleNow()
            ->whereIn('item_id', $item_ids)
            ->whereHas('mentions', fn ($mentions) => $mentions->where('user_id', $viewer_id))
            ->distinct()
            ->pluck('item_id')
            ->map(fn ($item_id) => (int) $item_id)
            ->values()
            ->all();

        return [
            'counts' => $counts,
            'mentioned_item_ids' => $mentioned_item_ids,
            'changed_at' => $changed_at,
        ];
    }

    /**
     * Milliseconds since the epoch, which lets the client drop a counter older than the one it
     * already shows (a socket event and an HTTP response can arrive in either order).
     */
    private function stamp(): int
    {
        return (int) now()->getPreciseTimestamp(3);
    }
}
