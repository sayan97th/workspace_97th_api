<?php

namespace App\Services\Feed;

use App\Events\NewFeedUpdate;
use App\Http\Resources\FeedUpdateResource;
use App\Models\BoardComment;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardItemActivityService;
use App\Services\Board\ScheduledCommentService;
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
    public function __construct(private readonly BoardItemActivityService $activity_service) {}

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
     * created, and again (from {@see ScheduledCommentService::publish()}) once a
     * scheduled one goes live.
     */
    public function broadcastUpdate(BoardItemComment|BoardComment $comment, WorkspaceNavigationItem $board, ?User $thread_author = null): void
    {
        $recipients = $this->resolveRecipients($board, $comment->mentions->pluck('user_id'), $thread_author);

        // The item changes behind a top-level item update are the same for every recipient.
        $activity = $comment instanceof BoardItemComment && isset(($bundle = $this->activity_service->forUpdates(collect([$comment])))[$comment->id])
            ? FeedUpdateResource::activityPayload($bundle[$comment->id])
            : null;

        foreach ($recipients as $recipient) {
            broadcast(new NewFeedUpdate($comment, $recipient, $activity));
        }
    }
}
