<?php

namespace App\Services\Feed;

use App\Events\NewFeedUpdate;
use App\Models\BoardComment;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Collection;

/**
 * Single entry point for delivering a comment/reply to the Update Feed —
 * the feed's equivalent of {@see NotificationService}.
 * Every trigger (a fresh comment, a reply, a scheduled update going live)
 * funnels through here rather than duplicating "resolve recipients +
 * broadcast" logic at each call site.
 */
class FeedService
{
    /**
     * Everyone who should receive this update live: every member of the
     * board's workspace (so the "All account" tab stays live for everyone),
     * plus whoever was `@mentioned` and the thread's original author — the
     * latter two cover the edge case of a board-only collaborator who isn't
     * a full workspace member.
     *
     * @param  Collection<int, int>  $mentioned_user_ids
     * @return Collection<int, User>
     */
    public function resolveRecipients(WorkspaceNavigationItem $board, Collection $mentioned_user_ids, ?User $thread_author): Collection
    {
        $recipients = $board->workspace->users;

        $extra_ids = $thread_author ? $mentioned_user_ids->push($thread_author->id) : $mentioned_user_ids;
        $missing_ids = $extra_ids->diff($recipients->pluck('id'));

        if ($missing_ids->isNotEmpty()) {
            $recipients = $recipients->concat(User::whereIn('id', $missing_ids)->get());
        }

        return $recipients->unique('id');
    }

    /**
     * Broadcasts `$comment` to every resolved recipient's private
     * `feed.{user_id}` channel. Called right after a comment/reply is
     * created, and again (from {@see publishDue()}) once a scheduled one
     * becomes due.
     */
    public function broadcastUpdate(BoardItemComment|BoardComment $comment, WorkspaceNavigationItem $board, ?User $thread_author = null): void
    {
        $recipients = $this->resolveRecipients($board, $comment->mentions->pluck('user_id'), $thread_author);

        foreach ($recipients as $recipient) {
            broadcast(new NewFeedUpdate($comment, $recipient));
        }
    }

    /**
     * Publishes every comment/reply (item- and board-level) whose
     * `scheduled_at` has come due: clears `scheduled_at` — so from here on
     * it behaves like a normal comment (visible in its thread, never
     * reprocessed by this method) — and broadcasts it to the feed exactly
     * like a freshly-posted update. Run every minute by the
     * `feed:publish-scheduled` command (see routes/console.php).
     */
    public function publishDue(): int
    {
        return $this->publishDueItemComments() + $this->publishDueBoardComments();
    }

    private function publishDueItemComments(): int
    {
        $due = BoardItemComment::query()
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->with(['parent.author'])
            ->get();

        foreach ($due as $comment) {
            $comment->update(['scheduled_at' => null]);

            $this->broadcastUpdate(
                $comment->fresh(['author', 'mentions', 'bookmarks', 'views', 'item.board.parent']),
                $comment->item->board,
                $comment->parent?->author,
            );
        }

        return $due->count();
    }

    private function publishDueBoardComments(): int
    {
        $due = BoardComment::query()
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->with(['parent.author'])
            ->get();

        foreach ($due as $comment) {
            $comment->update(['scheduled_at' => null]);

            $this->broadcastUpdate(
                $comment->fresh(['author', 'mentions', 'bookmarks', 'views', 'board.parent']),
                $comment->board,
                $comment->parent?->author,
            );
        }

        return $due->count();
    }
}
