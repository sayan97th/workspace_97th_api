<?php

namespace App\Http\Controllers\Board;

use App\Events\ItemCommentPosted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardItemCommentRequest;
use App\Http\Requests\Board\ToggleBoardItemCommentReactionRequest;
use App\Http\Requests\Board\UpdateBoardItemCommentRequest;
use App\Http\Resources\BoardItemCommentResource;
use App\Http\Resources\CommentRevisionResource;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardAutomationService;
use App\Services\Board\CommentAssignmentService;
use App\Services\Board\CommentThreadActionsService;
use App\Services\Board\ScheduledCommentService;
use App\Services\Feed\FeedService;
use App\Services\Notification\NotificationService;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BoardItemCommentController extends Controller
{
    public function __construct(
        private readonly NotificationService $notification_service,
        private readonly FeedService $feed_service,
        private readonly CommentThreadActionsService $comment_actions,
        private readonly BoardAutomationService $automation_service,
        private readonly CommentAssignmentService $assignment_service,
        private readonly ScheduledCommentService $scheduled_comments,
    ) {}

    /**
     * GET /api/boards/{item}/items/{board_item}/comments
     *
     * Top-level comments (updates) for the item, newest first, each with its
     * replies (oldest first) and like/reaction/view/attachment state.
     */
    public function index(WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);

        $comments = $board_item->comments()
            ->whereNull('parent_id')
            ->visibleNow()
            ->with($this->eagerLoads())
            ->pinnedFirst()
            ->get();

        return response()->json([
            'data' => BoardItemCommentResource::collection($comments),
        ]);
    }

    /**
     * POST /api/boards/{item}/items/{board_item}/comments
     *
     * Creates a comment, or (when `parent_id` is given) a reply — including
     * any `@mentions` and file attachments — in a single multipart request.
     */
    public function store(StoreBoardItemCommentRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $validated = $request->validated();

        $scheduled_at = $validated['scheduled_at'] ?? null;
        $assign_user_ids = collect($validated['assign_user_ids'] ?? [])->map(fn ($user_id) => (int) $user_id)->unique()->values();
        $assign_due_date = $validated['assign_due_date'] ?? null;
        $is_assigning = $assign_user_ids->isNotEmpty() || $assign_due_date !== null;

        // Checked before anything is created, so a comment that cannot become a task is not posted half done.
        if ($is_assigning) {
            BoardEditGate::authorize($item, $request->user());
            $this->assignment_service->resolveColumns($board_item, $assign_user_ids->isNotEmpty(), $assign_due_date !== null);
        }

        $comment = $board_item->comments()->create([
            'parent_id' => $validated['parent_id'] ?? null,
            'user_id' => $request->user()?->id,
            'body' => trim($validated['body'] ?? ''),
            'scheduled_at' => $scheduled_at,
        ]);

        $mentioned_user_ids = collect($validated['mentioned_user_ids'] ?? [])->unique();
        if ($mentioned_user_ids->isNotEmpty()) {
            $comment->mentions()->createMany(
                $mentioned_user_ids->map(fn ($user_id) => ['user_id' => $user_id])->all()
            );
        }

        $actor = $request->user();
        $notified_user_ids = collect($validated['notified_user_ids'] ?? []);

        if ($scheduled_at !== null) {
            // Nothing is sent yet, ScheduledCommentService notifies and broadcasts once it goes live.
            if ($actor) {
                $this->comment_actions->recordNotified($comment, $notified_user_ids, $actor);
            }
        } else {
            if ($actor) {
                $this->notifyCommentCreated($item, $board_item, $comment, $mentioned_user_ids, $actor);

                if ($notified_user_ids->isNotEmpty()) {
                    $this->comment_actions->notifyDirect(
                        comment: $comment,
                        notified_user_ids: $notified_user_ids,
                        actor: $actor,
                        board: $item,
                        link: "/boards/{$item->id}/pulses/{$board_item->id}",
                        action_target: sprintf('on "%s"', $board_item->name),
                        board_item: $board_item,
                    );
                }

                if ($is_assigning) {
                    $this->assignment_service->assign($item, $board_item, $assign_user_ids, $assign_due_date, $actor);
                }
            }

            $this->feed_service->autoFollowItem($board_item, $mentioned_user_ids->concat([$actor?->id])->filter());

            $this->automation_service->handleUpdatePosted($board_item, $comment, $request->user());

            broadcast(new ItemCommentPosted($comment))->toOthers();

            $this->feed_service->broadcastUpdate(
                $comment->fresh(['author', 'mentions.user', 'bookmarks', 'views', 'item.board.parent']),
                $item,
                $comment->parent?->author,
            );
        }

        foreach ($request->file('attachments', []) as $file) {
            $extension = $file->getClientOriginalExtension();
            $path = $file->storeAs(
                "board-comment-attachments/{$board_item->id}",
                Str::uuid().'.'.$extension,
                config('filesystems.app_disk')
            );

            $comment->attachments()->create([
                'uploaded_by_id' => $request->user()?->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'extension' => $extension,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize() ?? 0,
            ]);
        }

        return response()->json([
            'message' => 'Comment posted successfully.',
            'comment' => new BoardItemCommentResource($comment->fresh($this->eagerLoads())),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/items/{board_item}/comments/{comment}
     *
     * Edits a comment or reply's body — author-only, same as {@see destroy()}.
     */
    public function update(UpdateBoardItemCommentRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemComment $comment): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureCommentBelongsToItem($board_item, $comment);
        abort_if($comment->user_id !== $request->user()?->id, 403);

        $this->comment_actions->editBody($comment, trim($request->validated('body')), $request->user());

        return response()->json([
            'message' => 'Comment updated successfully.',
            'comment' => new BoardItemCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * GET /api/boards/{item}/items/{board_item}/comments/{comment}/revisions
     *
     * The bodies the comment had before each edit, newest edit first, for the
     * "(edited)" marker's history popover.
     */
    public function revisions(WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemComment $comment): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureCommentBelongsToItem($board_item, $comment);

        return response()->json([
            'data' => CommentRevisionResource::collection($comment->revisions()->with('editor')->get()),
        ]);
    }

    /**
     * POST /api/boards/{item}/items/{board_item}/comments/{comment}/pin
     *
     * Toggles whether the comment (or reply) is pinned, pinned updates sort
     * ahead of the rest of the thread and the Update Feed. Only people who can
     * edit the board may pin or unpin.
     */
    public function togglePin(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemComment $comment): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureCommentBelongsToItem($board_item, $comment);
        BoardEditGate::authorize($item, $request->user());

        $pinned = $this->comment_actions->togglePin($comment);

        return response()->json([
            'message' => $pinned ? 'Comment pinned successfully.' : 'Comment unpinned successfully.',
            'comment' => new BoardItemCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * POST /api/boards/{item}/items/{board_item}/comments/{comment}/resolve
     *
     * Toggles whether an update is resolved. Only top-level updates can be, a
     * reply belongs to its thread. Only people who can edit the board may
     * resolve or reopen.
     */
    public function toggleResolve(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemComment $comment): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureCommentBelongsToItem($board_item, $comment);
        BoardEditGate::authorize($item, $request->user());
        abort_if($comment->parent_id !== null, 422, 'Only an update can be resolved, not a reply.');

        $resolved = $this->comment_actions->toggleResolved($comment, $request->user());

        return response()->json([
            'message' => $resolved ? 'Thread resolved successfully.' : 'Thread reopened successfully.',
            'comment' => new BoardItemCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/items/{board_item}/comments/{comment}
     *
     * Author-only — this app has no roles/policy layer on Board controllers.
     * A soft delete: the row and its attachment files stay for
     * {@see CommentThreadActionsService::UNDO_WINDOW_DAYS} days so the drawer's
     * "Undo" can bring it back, `comments:purge-deleted` removes them for good
     * after that.
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemComment $comment): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureCommentBelongsToItem($board_item, $comment);
        abort_if($comment->user_id !== $request->user()?->id, 403);

        $comment->delete();

        return response()->json([
            'message' => 'Comment deleted successfully.',
        ]);
    }

    /**
     * POST /api/boards/{item}/items/{board_item}/comments/{comment_id}/restore
     *
     * The "Undo" of a delete: brings a soft deleted comment or reply back, with
     * its replies, reactions and attachments. Author-only and only inside the
     * undo window.
     */
    public function restore(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item, int $comment_id): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);

        $comment = BoardItemComment::onlyTrashed()->where('item_id', $board_item->id)->findOrFail($comment_id);
        abort_if($comment->user_id !== $request->user()?->id, 403);
        abort_if($comment->deleted_at->lt(now()->subDays(CommentThreadActionsService::UNDO_WINDOW_DAYS)), 410, 'This comment can no longer be restored.');
        abort_if($comment->parent_id !== null && $comment->parent === null, 409, 'The update this reply belongs to was deleted.');

        $comment->restore();

        return response()->json([
            'message' => 'Comment restored successfully.',
            'comment' => new BoardItemCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * POST /api/boards/{item}/items/{board_item}/comments/{comment}/like
     *
     * Toggles the current user's like on the comment or reply.
     */
    public function toggleLike(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemComment $comment): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureCommentBelongsToItem($board_item, $comment);
        $user_id = $request->user()?->id;

        $like = $comment->likes()->where('user_id', $user_id)->first();
        $like ? $like->delete() : $comment->likes()->create(['user_id' => $user_id]);

        return response()->json([
            'comment' => new BoardItemCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * POST /api/boards/{item}/items/{board_item}/comments/{comment}/reactions
     *
     * Toggles the current user's reaction with the given emoji.
     */
    public function toggleReaction(ToggleBoardItemCommentReactionRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemComment $comment): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureCommentBelongsToItem($board_item, $comment);
        $user_id = $request->user()?->id;
        $emoji = $request->validated('emoji');

        $reaction = $comment->reactions()->where(['user_id' => $user_id, 'emoji' => $emoji])->first();

        if ($reaction) {
            $reaction->delete();
        } else {
            $comment->reactions()->create(['user_id' => $user_id, 'emoji' => $emoji]);

            if (($actor = $request->user()) && $comment->author) {
                $this->notification_service->notify(
                    recipient: $comment->author,
                    actor: $actor,
                    type: Notification::TYPE_REACTIONS,
                    board: $item,
                    action_label: 'Reacted to your comment',
                    action_target: sprintf('on "%s"', $board_item->name),
                    link: "/boards/{$item->id}/pulses/{$board_item->id}",
                    comment: $comment,
                );
            }
        }

        return response()->json([
            'comment' => new BoardItemCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * POST /api/boards/{item}/items/{board_item}/comments/{comment}/seen
     *
     * Toggles the current user's "mark as seen" state on the comment.
     */
    public function toggleSeen(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemComment $comment): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureCommentBelongsToItem($board_item, $comment);
        $user_id = $request->user()?->id;

        $view = $comment->views()->where('user_id', $user_id)->first();
        $view ? $view->delete() : $comment->views()->create(['user_id' => $user_id]);

        return response()->json([
            'comment' => new BoardItemCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * POST /api/boards/{item}/items/{board_item}/comments/{comment}/bookmark
     *
     * Toggles the current user's bookmark on the comment or reply, the same
     * bookmark the Update Feed's "Bookmarked" tab lists.
     */
    public function toggleBookmark(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemComment $comment): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureCommentBelongsToItem($board_item, $comment);
        $user_id = $request->user()?->id;

        $bookmark = $comment->bookmarks()->where('user_id', $user_id)->first();
        $bookmark ? $bookmark->delete() : $comment->bookmarks()->create(['user_id' => $user_id]);

        return response()->json([
            'comment' => new BoardItemCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * GET /api/boards/{item}/items/{board_item}/comments/scheduled
     *
     * The current user's own comments and replies on this item that are still
     * waiting on a future `scheduled_at`, soonest first.
     */
    public function scheduled(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);

        $comments = $board_item->comments()
            ->scheduledBy($request->user()->id)
            ->with($this->eagerLoads(false))
            ->orderBy('scheduled_at')
            ->get();

        return response()->json([
            'data' => BoardItemCommentResource::collection($comments),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/items/{board_item}/comments/{comment}/schedule
     *
     * Moves a scheduled comment to a new time, or sends it right now when
     * `scheduled_at` is null. Author-only, and only while it is still scheduled.
     * Cancelling a schedule is the normal DELETE.
     */
    public function updateSchedule(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemComment $comment): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureCommentBelongsToItem($board_item, $comment);
        abort_if($comment->user_id !== $request->user()?->id, 403);
        abort_if($comment->scheduled_at === null || $comment->scheduled_at->isPast(), 422, 'This comment is not scheduled.');

        $validated = $request->validate([
            'scheduled_at' => ['present', 'nullable', 'date', 'after:now'],
        ]);

        if ($validated['scheduled_at'] === null) {
            $this->scheduled_comments->publish($comment);
        } else {
            $comment->update(['scheduled_at' => $validated['scheduled_at']]);
        }

        return response()->json([
            'message' => $validated['scheduled_at'] === null ? 'Comment sent.' : 'Comment rescheduled.',
            'comment' => new BoardItemCommentResource($comment->fresh($this->eagerLoads(false))),
        ]);
    }

    /**
     * Notifies the parent comment's author (on a reply) and every mentioned
     * user (on a mention), skipping self-notifications, via
     * {@see NotificationService}.
     *
     * @param  Collection<int, int>  $mentioned_user_ids
     */
    private function notifyCommentCreated(WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemComment $comment, Collection $mentioned_user_ids, User $actor): void
    {
        $link = "/boards/{$item->id}/pulses/{$board_item->id}";

        if ($comment->parent_id && $comment->parent?->author) {
            $this->notification_service->notify(
                recipient: $comment->parent->author,
                actor: $actor,
                type: Notification::TYPE_REPLIED_THREAD,
                board: $item,
                action_label: 'Replied to your comment',
                action_target: sprintf('on "%s"', $board_item->name),
                link: $link,
                comment: $comment,
            );
        }

        foreach ($mentioned_user_ids as $mentioned_user_id) {
            if ($mentioned_user = User::find($mentioned_user_id)) {
                $this->notification_service->notify(
                    recipient: $mentioned_user,
                    actor: $actor,
                    type: Notification::TYPE_MENTIONED,
                    board: $item,
                    action_label: 'Mentioned you',
                    action_target: sprintf('in a comment on "%s"', $board_item->name),
                    link: $link,
                    comment: $comment,
                );
            }
        }
    }

    /**
     * Relations every {@link BoardItemCommentResource} needs eager-loaded,
     * one level deep into replies unless `$with_replies` is off.
     *
     * @return array<int, string>
     */
    private function eagerLoads(bool $with_replies = true): array
    {
        $own = ['author', 'resolvedBy', 'likes', 'reactions.user', 'views.user', 'bookmarks', 'mentions', 'notifiedUsers', 'attachments'];

        return $with_replies
            ? [...$own, ...array_map(fn ($relation) => "replies.{$relation}", $own)]
            : $own;
    }

    /**
     * Guard: abort with 404 when the item is not part of the board.
     */
    private function ensureItemBelongsToBoard(WorkspaceNavigationItem $item, BoardItem $board_item): void
    {
        abort_if($board_item->board_id !== $item->id, 404);
    }

    /**
     * Guard: abort with 404 when the comment is not on this item.
     */
    private function ensureCommentBelongsToItem(BoardItem $board_item, BoardItemComment $comment): void
    {
        abort_if($comment->item_id !== $board_item->id, 404);
    }
}
