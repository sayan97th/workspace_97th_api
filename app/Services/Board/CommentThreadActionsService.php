<?php

namespace App\Services\Board;

use App\Models\BoardComment;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Collection;

/**
 * The handful of comment-thread actions shared by
 * {@see \App\Http\Controllers\Board\BoardItemCommentController} and
 * {@see \App\Http\Controllers\Board\BoardCommentController} — kept as a
 * small service rather than merging the two (near-identical but still
 * distinct) controllers together.
 */
class CommentThreadActionsService
{
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
     * Records `$notified_user_ids` on `$comment` (deduped, self excluded)
     * and sends each one a {@see Notification::TYPE_NOTIFIED} notification —
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
        $notified_user_ids = $notified_user_ids->unique()->reject(fn ($user_id) => (int) $user_id === $actor->id);
        if ($notified_user_ids->isEmpty()) {
            return;
        }

        $comment->notifiedUsers()->createMany(
            $notified_user_ids->map(fn ($user_id) => ['user_id' => $user_id])->all()
        );

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
                );
            }
        }
    }
}
