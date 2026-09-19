<?php

namespace App\Http\Controllers\Board;

use App\Events\BoardCommentPosted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardCommentRequest;
use App\Http\Requests\Board\ToggleBoardCommentReactionRequest;
use App\Http\Requests\Board\UpdateBoardCommentRequest;
use App\Http\Resources\BoardCommentResource;
use App\Http\Resources\CommentRevisionResource;
use App\Models\BoardComment;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\CommentThreadActionsService;
use App\Services\Feed\FeedService;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The board-wide discussion feed shown by `BoardDiscussionDrawer` on the
 * frontend, opened via the header's "Board updates" button. Structurally
 * the same feature as {@see BoardItemCommentController}, scoped to a whole
 * board (`WorkspaceNavigationItem`) instead of a single `BoardItem`.
 */
class BoardCommentController extends Controller
{
    public function __construct(
        private readonly NotificationService $notification_service,
        private readonly FeedService $feed_service,
        private readonly CommentThreadActionsService $comment_actions,
    ) {}

    /**
     * GET /api/boards/{item}/comments
     *
     * Top-level comments (updates) for the board, newest first, each with
     * its replies (oldest first) and like/reaction/view/attachment state.
     *
     * Doubles as the "I opened the discussion drawer" signal: it upserts the
     * requesting user's {@see BoardDiscussionView}, which is what flips the
     * header's "Board updates" badge from red back to gray.
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $comments = $item->comments()
            ->whereNull('parent_id')
            ->visibleNow()
            ->with($this->eagerLoads())
            ->pinnedFirst()
            ->get();

        if ($user_id = $request->user()?->id) {
            $item->discussionViews()->updateOrCreate(
                ['user_id' => $user_id],
                ['last_viewed_at' => now()]
            );
        }

        return response()->json([
            'data' => BoardCommentResource::collection($comments),
        ]);
    }

    /**
     * POST /api/boards/{item}/comments
     *
     * Creates a comment, or (when `parent_id` is given) a reply — including
     * any `@mentions` and file attachments — in a single multipart request.
     */
    public function store(StoreBoardCommentRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();

        $comment = $item->comments()->create([
            'parent_id' => $validated['parent_id'] ?? null,
            'user_id' => $request->user()?->id,
            'body' => trim($validated['body'] ?? ''),
        ]);

        $mentioned_user_ids = collect($validated['mentioned_user_ids'] ?? [])->unique();
        if ($mentioned_user_ids->isNotEmpty()) {
            $comment->mentions()->createMany(
                $mentioned_user_ids->map(fn ($user_id) => ['user_id' => $user_id])->all()
            );
        }

        if ($actor = $request->user()) {
            $this->notifyCommentCreated($item, $comment, $mentioned_user_ids, $actor);

            $notified_user_ids = collect($validated['notified_user_ids'] ?? []);
            if ($notified_user_ids->isNotEmpty()) {
                $this->comment_actions->notifyDirect(
                    comment: $comment,
                    notified_user_ids: $notified_user_ids,
                    actor: $actor,
                    board: $item,
                    link: "/boards/{$item->id}",
                    action_target: sprintf('on the Board "%s"', $item->label),
                );
            }
        }

        broadcast(new BoardCommentPosted($comment))->toOthers();

        $this->feed_service->broadcastUpdate(
            $comment->fresh(['author', 'mentions.user', 'bookmarks', 'views', 'board.parent']),
            $item,
            $comment->parent?->author,
        );

        foreach ($request->file('attachments', []) as $file) {
            $extension = $file->getClientOriginalExtension();
            $path = $file->storeAs(
                "board-discussion-attachments/{$item->id}",
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
            'message' => 'Update posted successfully.',
            'comment' => new BoardCommentResource($comment->fresh($this->eagerLoads())),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/comments/{comment}
     *
     * Edits a comment or reply's body — author-only, same as {@see destroy()}.
     */
    public function update(UpdateBoardCommentRequest $request, WorkspaceNavigationItem $item, BoardComment $comment): JsonResponse
    {
        $this->ensureCommentBelongsToBoard($item, $comment);
        abort_if($comment->user_id !== $request->user()?->id, 403);

        $this->comment_actions->editBody($comment, trim($request->validated('body')), $request->user());

        return response()->json([
            'message' => 'Update edited successfully.',
            'comment' => new BoardCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * GET /api/boards/{item}/comments/{comment}/revisions
     *
     * The bodies the update had before each edit, newest edit first, for the
     * "(edited)" marker's history popover.
     */
    public function revisions(WorkspaceNavigationItem $item, BoardComment $comment): JsonResponse
    {
        $this->ensureCommentBelongsToBoard($item, $comment);

        return response()->json([
            'data' => CommentRevisionResource::collection($comment->revisions()->with('editor')->get()),
        ]);
    }

    /**
     * POST /api/boards/{item}/comments/{comment}/pin
     *
     * Toggles whether the comment (or reply) is pinned — pinned updates sort
     * ahead of the rest of the thread and the Update Feed.
     */
    public function togglePin(Request $request, WorkspaceNavigationItem $item, BoardComment $comment): JsonResponse
    {
        $this->ensureCommentBelongsToBoard($item, $comment);

        $pinned = $this->comment_actions->togglePin($comment);

        return response()->json([
            'message' => $pinned ? 'Update pinned successfully.' : 'Update unpinned successfully.',
            'comment' => new BoardCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/comments/{comment}
     *
     * Author-only — this app has no roles/policy layer on Board controllers.
     * Also removes the comment's attachment files from the `public` disk, so
     * a deleted comment doesn't leave orphaned uploads behind.
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardComment $comment): JsonResponse
    {
        $this->ensureCommentBelongsToBoard($item, $comment);
        abort_if($comment->user_id !== $request->user()?->id, 403);

        $this->deleteAttachmentFiles($comment);
        $comment->delete();

        return response()->json([
            'message' => 'Update deleted successfully.',
        ]);
    }

    /**
     * POST /api/boards/{item}/comments/{comment}/like
     *
     * Toggles the current user's like on the comment or reply.
     */
    public function toggleLike(Request $request, WorkspaceNavigationItem $item, BoardComment $comment): JsonResponse
    {
        $this->ensureCommentBelongsToBoard($item, $comment);
        $user_id = $request->user()?->id;

        $like = $comment->likes()->where('user_id', $user_id)->first();
        $like ? $like->delete() : $comment->likes()->create(['user_id' => $user_id]);

        return response()->json([
            'comment' => new BoardCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * POST /api/boards/{item}/comments/{comment}/reactions
     *
     * Toggles the current user's reaction with the given emoji.
     */
    public function toggleReaction(ToggleBoardCommentReactionRequest $request, WorkspaceNavigationItem $item, BoardComment $comment): JsonResponse
    {
        $this->ensureCommentBelongsToBoard($item, $comment);
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
                    action_label: 'Reacted to your update',
                    action_target: sprintf('on the Board "%s"', $item->label),
                    link: "/boards/{$item->id}",
                );
            }
        }

        return response()->json([
            'comment' => new BoardCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * POST /api/boards/{item}/comments/{comment}/seen
     *
     * Toggles the current user's "mark as seen" state on the comment.
     */
    public function toggleSeen(Request $request, WorkspaceNavigationItem $item, BoardComment $comment): JsonResponse
    {
        $this->ensureCommentBelongsToBoard($item, $comment);
        $user_id = $request->user()?->id;

        $view = $comment->views()->where('user_id', $user_id)->first();
        $view ? $view->delete() : $comment->views()->create(['user_id' => $user_id]);

        return response()->json([
            'comment' => new BoardCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * Notifies the parent comment's author (on a reply) and every mentioned
     * user (on a mention), skipping self-notifications, via
     * {@see NotificationService}.
     *
     * @param  Collection<int, int>  $mentioned_user_ids
     */
    private function notifyCommentCreated(WorkspaceNavigationItem $item, BoardComment $comment, Collection $mentioned_user_ids, User $actor): void
    {
        $link = "/boards/{$item->id}";

        if ($comment->parent_id && $comment->parent?->author) {
            $this->notification_service->notify(
                recipient: $comment->parent->author,
                actor: $actor,
                type: Notification::TYPE_REPLIED_UPDATE,
                board: $item,
                action_label: 'Replied to your update',
                action_target: sprintf('on the Board "%s"', $item->label),
                link: $link,
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
                    action_target: sprintf('in a comment on the Board "%s"', $item->label),
                    link: $link,
                );
            }
        }
    }

    /**
     * Relations every {@link BoardCommentResource} needs eager-loaded, one
     * level deep into replies.
     *
     * @return array<int, string>
     */
    private function eagerLoads(): array
    {
        $own = ['author', 'likes', 'reactions.user', 'views.user', 'mentions', 'notifiedUsers', 'attachments'];

        return [
            ...$own,
            ...array_map(fn ($relation) => "replies.{$relation}", $own),
        ];
    }

    /**
     * Deletes every attachment file the comment has on the `public` disk —
     * skipping any that are already missing, since a repeat delete or a
     * manually-cleared disk shouldn't turn this into a hard failure.
     */
    private function deleteAttachmentFiles(BoardComment $comment): void
    {
        foreach ($comment->attachments as $attachment) {
            if (Storage::disk(config('filesystems.app_disk'))->exists($attachment->file_path)) {
                Storage::disk(config('filesystems.app_disk'))->delete($attachment->file_path);
            }
        }
    }

    /**
     * Guard: abort with 404 when the comment is not on this board.
     */
    private function ensureCommentBelongsToBoard(WorkspaceNavigationItem $item, BoardComment $comment): void
    {
        abort_if($comment->board_id !== $item->id, 404);
    }
}
