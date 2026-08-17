<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardItemCommentRequest;
use App\Http\Requests\Board\ToggleBoardItemCommentReactionRequest;
use App\Http\Requests\Board\UpdateBoardItemCommentRequest;
use App\Http\Resources\BoardItemCommentResource;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Feed\FeedService;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BoardItemCommentController extends Controller
{
    public function __construct(
        private readonly NotificationService $notification_service,
        private readonly FeedService $feed_service,
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
            ->orderByDesc('created_at')
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

        $comment = $board_item->comments()->create([
            'parent_id' => $validated['parent_id'] ?? null,
            'user_id' => $request->user()?->id,
            'body' => $validated['body'] ?? '',
        ]);

        $mentioned_user_ids = collect($validated['mentioned_user_ids'] ?? [])->unique();
        if ($mentioned_user_ids->isNotEmpty()) {
            $comment->mentions()->createMany(
                $mentioned_user_ids->map(fn ($user_id) => ['user_id' => $user_id])->all()
            );
        }

        if ($actor = $request->user()) {
            $this->notifyCommentCreated($item, $board_item, $comment, $mentioned_user_ids, $actor);
        }

        $this->feed_service->broadcastUpdate(
            $comment->fresh(['author', 'mentions', 'bookmarks', 'views', 'item.board.parent']),
            $item,
            $comment->parent?->author,
        );

        foreach ($request->file('attachments', []) as $file) {
            $extension = $file->getClientOriginalExtension();
            $path = $file->storeAs(
                "board-comment-attachments/{$board_item->id}",
                Str::uuid().'.'.$extension,
                'public'
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

        $comment->update(['body' => $request->validated('body'), 'edited_at' => now()]);

        return response()->json([
            'message' => 'Comment updated successfully.',
            'comment' => new BoardItemCommentResource($comment->fresh($this->eagerLoads())),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/items/{board_item}/comments/{comment}
     *
     * Author-only — this app has no roles/policy layer on Board controllers.
     * Also removes the comment's attachment files from the `public` disk, so
     * a deleted comment doesn't leave orphaned uploads behind.
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemComment $comment): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureCommentBelongsToItem($board_item, $comment);
        abort_if($comment->user_id !== $request->user()?->id, 403);

        $this->deleteAttachmentFiles($comment);
        $comment->delete();

        return response()->json([
            'message' => 'Comment deleted successfully.',
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
                );
            }
        }
    }

    /**
     * Relations every {@link BoardItemCommentResource} needs eager-loaded,
     * one level deep into replies.
     *
     * @return array<int, string>
     */
    private function eagerLoads(): array
    {
        $own = ['author', 'likes', 'reactions.user', 'views', 'mentions', 'attachments'];

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
    private function deleteAttachmentFiles(BoardItemComment $comment): void
    {
        foreach ($comment->attachments as $attachment) {
            if (Storage::disk('public')->exists($attachment->file_path)) {
                Storage::disk('public')->delete($attachment->file_path);
            }
        }
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
