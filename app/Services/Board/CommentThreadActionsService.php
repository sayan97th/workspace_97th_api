<?php

namespace App\Services\Board;

use App\Http\Controllers\Board\BoardCommentController;
use App\Http\Controllers\Board\BoardItemCommentController;
use App\Models\BoardComment;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The handful of comment-thread actions shared by
 * {@see BoardItemCommentController} and
 * {@see BoardCommentController}, kept as a
 * small service rather than merging the two (near-identical but still
 * distinct) controllers together.
 */
class CommentThreadActionsService
{
    /**
     * How many days a deleted comment can still be restored, after that
     * `comments:purge-deleted` removes it and its files for good.
     */
    public const UNDO_WINDOW_DAYS = 7;

    public function __construct(
        private readonly NotificationService $notification_service,
    ) {}

    /**
     * Toggles `pinned` on a comment or reply.
     */
    public function togglePin(BoardItemComment|BoardComment $comment): bool
    {
        $comment->update(['pinned' => ! $comment->pinned]);

        return $comment->pinned;
    }

    /**
     * Marks a top-level update as resolved by `$user`, or reopens it when it
     * already is. Returns whether it ends up resolved.
     */
    public function toggleResolved(BoardItemComment|BoardComment $comment, User $user): bool
    {
        $comment->update($comment->resolved_at === null
            ? ['resolved_at' => now(), 'resolved_by_id' => $user->id]
            : ['resolved_at' => null, 'resolved_by_id' => null]);

        return $comment->resolved_at !== null;
    }

    /**
     * Replaces a comment or reply's body, keeping the body it had until now
     * as a revision so the "(edited)" marker can show earlier versions. An
     * edit that leaves the text unchanged is not an edit: no revision, and
     * `edited_at` stays as it was.
     */
    public function editBody(BoardItemComment|BoardComment $comment, string $body, User $editor): void
    {
        if ($comment->body === $body) {
            return;
        }

        DB::transaction(function () use ($comment, $body, $editor) {
            $comment->revisions()->create([
                'edited_by_id' => $editor->id,
                'body' => $comment->body,
                'body_written_at' => $comment->edited_at ?? $comment->created_at,
            ]);

            $comment->update(['body' => $body, 'edited_at' => now()]);
        });
    }

    /**
     * Records `$notified_user_ids` on `$comment` (deduped, self excluded)
     * and sends each one a {@see Notification::TYPE_NOTIFIED} notification,
     * the composer's "Notify" action, distinct from `@mentions`: the person
     * is not shown inline in the comment body.
     *
     * @param  Collection<int, int>  $notified_user_ids
     */
    public function notifyDirect(
        BoardItemComment|BoardComment $comment,
        Collection $notified_user_ids,
        User $actor,
        WorkspaceNavigationItem $board,
        string $link,
        string $action_target,
        ?BoardItem $board_item = null,
    ): void {
        $recorded_ids = $this->recordNotified($comment, $notified_user_ids, $actor);

        $this->sendNotified($recorded_ids, $actor, $board, $link, $action_target, $board_item, $comment);
    }

    /**
     * Stores who a comment notifies (deduped, the author excluded) without
     * sending anything yet, so a scheduled comment can keep its "Notify" list
     * until it goes live. Returns the ids that were stored.
     *
     * @param  Collection<int, int>  $notified_user_ids
     * @return Collection<int, int>
     */
    public function recordNotified(BoardItemComment|BoardComment $comment, Collection $notified_user_ids, User $actor): Collection
    {
        $notified_user_ids = $notified_user_ids->unique()->reject(fn ($user_id) => (int) $user_id === $actor->id)->values();

        if ($notified_user_ids->isNotEmpty()) {
            $comment->notifiedUsers()->createMany(
                $notified_user_ids->map(fn ($user_id) => ['user_id' => $user_id])->all()
            );
        }

        return $notified_user_ids;
    }

    /**
     * Sends the "Wants your attention" notification to each id.
     *
     * @param  Collection<int, int>  $notified_user_ids
     */
    public function sendNotified(
        Collection $notified_user_ids,
        User $actor,
        WorkspaceNavigationItem $board,
        string $link,
        string $action_target,
        ?BoardItem $board_item = null,
        BoardItemComment|BoardComment|null $comment = null,
    ): void {
        foreach ($notified_user_ids as $notified_user_id) {
            if ($notified_user = User::find($notified_user_id)) {
                $this->notification_service->notify(
                    recipient: $notified_user,
                    actor: $actor,
                    type: Notification::TYPE_NOTIFIED,
                    board: $board,
                    action_label: 'Wants your attention',
                    action_target: $action_target,
                    link: $link,
                    board_item: $board_item,
                    comment: $comment,
                );
            }
        }
    }
}
