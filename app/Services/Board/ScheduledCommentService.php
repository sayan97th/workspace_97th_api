<?php

namespace App\Services\Board;

use App\Events\BoardCommentPosted;
use App\Events\ItemCommentPosted;
use App\Models\BoardComment;
use App\Models\BoardItemComment;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Feed\FeedService;
use App\Services\Notification\NotificationService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Takes a scheduled comment or reply live. A comment with a future
 * `scheduled_at` is invisible everywhere and has notified nobody, so going
 * live does everything a freshly posted comment does: it clears
 * `scheduled_at`, notifies the parent author, the `@mentioned` people and the
 * "Notify" list, pushes the "N new updates" event to open drawers, delivers it
 * to the Update Feed and runs the board's "update posted" automations.
 *
 * Used by the `feed:publish-scheduled` command when a schedule comes due and
 * by the drawers' "Send now" action.
 */
class ScheduledCommentService
{
    public function __construct(
        private readonly NotificationService $notification_service,
        private readonly FeedService $feed_service,
        private readonly CommentThreadActionsService $comment_actions,
        private readonly BoardAutomationService $automation_service,
    ) {}

    /**
     * Publishes every comment and reply, item and board level, whose
     * `scheduled_at` has come due. Returns how many went live.
     */
    public function publishDue(): int
    {
        $published = 0;

        foreach ([BoardItemComment::class, BoardComment::class] as $model_class) {
            /** @var EloquentCollection<int, BoardItemComment|BoardComment> $due */
            $due = $model_class::query()
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<=', now())
                ->get();

            foreach ($due as $comment) {
                $this->publish($comment);
                $published++;
            }
        }

        return $published;
    }

    /**
     * Makes one scheduled comment live right now.
     */
    public function publish(BoardItemComment|BoardComment $comment): void
    {
        $comment->update(['scheduled_at' => null]);

        $is_item = $comment instanceof BoardItemComment;
        $comment->load($is_item ? ['author', 'parent.author', 'mentions.user', 'notifiedUsers', 'bookmarks', 'views', 'item.board.parent'] : ['author', 'parent.author', 'mentions.user', 'notifiedUsers', 'bookmarks', 'views', 'board.parent']);

        $board = $is_item ? $comment->item->board : $comment->board;
        $actor = $comment->author;

        if ($actor !== null) {
            $this->notifyPublished($comment, $board, $actor);
        }

        $is_item ? broadcast(new ItemCommentPosted($comment)) : broadcast(new BoardCommentPosted($comment));

        $this->feed_service->broadcastUpdate($comment, $board, $comment->parent?->author);

        if ($is_item) {
            $this->automation_service->handleUpdatePosted($comment->item, $comment, $actor);
        }
    }

    /**
     * The notifications a comment sends when it goes live: reply, mentions and
     * the composer's "Notify" list.
     */
    private function notifyPublished(BoardItemComment|BoardComment $comment, WorkspaceNavigationItem $board, User $actor): void
    {
        $is_item = $comment instanceof BoardItemComment;
        $link = $is_item ? "/boards/{$board->id}/pulses/{$comment->item_id}" : "/boards/{$board->id}";
        $action_target = $is_item ? sprintf('on "%s"', $comment->item->name) : sprintf('on the Board "%s"', $board->label);

        if ($comment->parent?->author) {
            $this->notification_service->notify(
                recipient: $comment->parent->author,
                actor: $actor,
                type: $is_item ? Notification::TYPE_REPLIED_THREAD : Notification::TYPE_REPLIED_UPDATE,
                board: $board,
                action_label: $is_item ? 'Replied to your comment' : 'Replied to your update',
                action_target: $action_target,
                link: $link,
            );
        }

        foreach ($comment->mentions as $mention) {
            if ($mention->user) {
                $this->notification_service->notify(
                    recipient: $mention->user,
                    actor: $actor,
                    type: Notification::TYPE_MENTIONED,
                    board: $board,
                    action_label: 'Mentioned you',
                    action_target: $is_item ? sprintf('in a comment on "%s"', $comment->item->name) : sprintf('in a comment on the Board "%s"', $board->label),
                    link: $link,
                );
            }
        }

        $this->comment_actions->sendNotified(
            $comment->notifiedUsers->pluck('user_id'),
            $actor,
            $board,
            $link,
            $action_target,
            $is_item ? $comment->item : null,
        );
    }
}
