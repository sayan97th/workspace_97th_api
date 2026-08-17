<?php

namespace App\Http\Controllers\Feed;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeedUpdateResource;
use App\Models\BoardComment;
use App\Models\BoardItemComment;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Feed\FeedService;
use App\Services\Notification\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The Update Feed opened from the AppTopBar feed button
 * (`UpdateFeedPanel`/`UpdateFeedCard` on the frontend) — a live stream of
 * the comment threads that already exist as {@see BoardItemComment} (item
 * "Updates" tabs) and {@see BoardComment} (the board-wide discussion
 * drawer). Unlike `NotificationController`, which surfaces short "X did Y"
 * alerts, this reads the actual comment content, so it queries the two
 * comment tables directly rather than a dedicated feed table.
 */
class FeedUpdateController extends Controller
{
    public function __construct(
        private readonly NotificationService $notification_service,
        private readonly FeedService $feed_service,
    ) {}

    /**
     * GET /api/feed/updates?tab=all|mentioned|bookmarked|account|scheduled&board_id=
     *
     * Newest 50 updates (comments + replies, item- and board-level merged)
     * matching the tab, newest first. Mirrors `NotificationController::index()`'s
     * flat `limit(50)` — no cursor pagination, same simplicity tradeoff.
     */
    public function index(Request $request): JsonResponse
    {
        $tab = (string) $request->query('tab', 'all');
        $board_id = $request->integer('board_id') ?: null;
        $user = $request->user();

        if ($tab === 'scheduled') {
            $item_comments = BoardItemComment::query()->scheduledBy($user->id)
                ->when($board_id, fn (Builder $q) => $q->whereHas('item.board', fn (Builder $bq) => $bq->where('id', $board_id)))
                ->with($this->itemLoads())->get();
            $board_comments = BoardComment::query()->scheduledBy($user->id)
                ->when($board_id, fn (Builder $q) => $q->whereHas('board', fn (Builder $bq) => $bq->where('id', $board_id)))
                ->with($this->boardLoads())->get();
        } else {
            $item_comments = $this->scopedQuery(BoardItemComment::class, $user, $tab, $board_id)->with($this->itemLoads())->get();
            $board_comments = $this->scopedQuery(BoardComment::class, $user, $tab, $board_id)->with($this->boardLoads())->get();
        }

        $data = $item_comments->concat($board_comments)
            ->sortByDesc('created_at')
            ->take(50)
            ->map(fn ($comment) => new FeedUpdateResource($comment))
            ->values();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/feed/boards
     *
     * Boards contributing to the "all" tab's reach, each with a live count,
     * prefixed with a synthetic "All boards in my feed" total — powers the
     * drawer's left sidebar filter.
     */
    public function boards(Request $request): JsonResponse
    {
        $user = $request->user();

        $item_counts = BoardItemComment::query()
            ->visibleNow()
            ->where($this->allScopeClosure($user))
            ->whereHas('item.board', fn (Builder $q) => $q->whereIn('workspace_id', $user->workspaces()->pluck('workspaces.id')))
            ->join('board_items', 'board_item_comments.item_id', '=', 'board_items.id')
            ->selectRaw('board_items.board_id as feed_board_id, count(*) as feed_count')
            ->groupBy('feed_board_id')
            ->pluck('feed_count', 'feed_board_id');

        $board_counts = BoardComment::query()
            ->visibleNow()
            ->where($this->allScopeClosure($user))
            ->whereHas('board', fn (Builder $q) => $q->whereIn('workspace_id', $user->workspaces()->pluck('workspaces.id')))
            ->selectRaw('board_id as feed_board_id, count(*) as feed_count')
            ->groupBy('feed_board_id')
            ->pluck('feed_count', 'feed_board_id');

        $combined = [];
        foreach ([$item_counts->all(), $board_counts->all()] as $counts) {
            foreach ($counts as $board_id => $count) {
                $combined[$board_id] = ($combined[$board_id] ?? 0) + $count;
            }
        }

        $boards = WorkspaceNavigationItem::whereIn('id', array_keys($combined))->get(['id', 'label'])->keyBy('id');

        $rows = collect($combined)
            ->map(function ($count, $board_id) use ($boards) {
                $board = $boards->get($board_id);

                return [
                    'id' => (string) $board_id,
                    'name' => $board !== null ? $board->label : __('Deleted board'),
                    'count' => $count,
                ];
            })
            ->sortByDesc('count')
            ->values();

        $all_boards_row = collect([[
            'id' => 'all-boards',
            'name' => 'All boards in my feed',
            'count' => array_sum($combined),
        ]]);

        return response()->json(['data' => $all_boards_row->concat($rows)]);
    }

    /**
     * GET /api/feed/unread-count
     *
     * Powers the AppTopBar feed button's badge — unread count over the same
     * reach as the "all" tab (own posts are never unread, so they're
     * excluded rather than double-checked).
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspace_ids = $user->workspaces()->pluck('workspaces.id');
        $count = 0;

        foreach ([BoardItemComment::class, BoardComment::class] as $model_class) {
            $is_item = $model_class === BoardItemComment::class;

            $count += $model_class::query()
                ->visibleNow()
                ->where('user_id', '!=', $user->id)
                ->where(function (Builder $q) use ($user) {
                    $q->whereHas('mentions', fn (Builder $mq) => $mq->where('user_id', $user->id))
                        ->orWhereHas('parent', fn (Builder $pq) => $pq->where('user_id', $user->id));
                })
                ->whereHas($is_item ? 'item.board' : 'board', fn (Builder $q) => $q->whereIn('workspace_id', $workspace_ids))
                ->whereDoesntHave('views', fn (Builder $q) => $q->where('user_id', $user->id))
                ->count();
        }

        return response()->json(['data' => ['unread_count' => $count]]);
    }

    /**
     * POST /api/feed/updates/{id}/bookmark
     */
    public function toggleBookmark(Request $request, string $id): JsonResponse
    {
        $comment = $this->resolveComment($id);
        $user_id = $request->user()->id;

        $bookmark = $comment->bookmarks()->where('user_id', $user_id)->first();
        $bookmark ? $bookmark->delete() : $comment->bookmarks()->create(['user_id' => $user_id]);

        return response()->json(['data' => new FeedUpdateResource($this->refreshed($comment))]);
    }

    /**
     * POST /api/feed/updates/{id}/like
     */
    public function toggleLike(Request $request, string $id): JsonResponse
    {
        $comment = $this->resolveComment($id);
        $user_id = $request->user()->id;

        $like = $comment->likes()->where('user_id', $user_id)->first();
        $like ? $like->delete() : $comment->likes()->create(['user_id' => $user_id]);

        return response()->json(['data' => new FeedUpdateResource($this->refreshed($comment))]);
    }

    /**
     * POST /api/feed/updates/{id}/seen
     *
     * Idempotent create-only — the feed never lets a card go back to unread.
     */
    public function markSeen(Request $request, string $id): JsonResponse
    {
        $comment = $this->resolveComment($id);
        $comment->views()->firstOrCreate(['user_id' => $request->user()->id]);

        return response()->json(['data' => new FeedUpdateResource($this->refreshed($comment))]);
    }

    /**
     * POST /api/feed/updates/{id}/reply
     *
     * Posts a reply from the card's inline composer — `{id}` may itself be a
     * reply, in which case this attaches to its parent (one level of
     * nesting only, mirroring `StoreBoardItemCommentRequest`).
     */
    public function reply(Request $request, string $id): JsonResponse
    {
        $comment = $this->resolveComment($id);
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'mentioned_user_ids' => ['sometimes', 'array'],
            'mentioned_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $reply = $this->createReply($comment, $validated, null);

        $this->notifyAndBroadcastReply($comment, $reply, collect($validated['mentioned_user_ids'] ?? [])->unique(), $request->user());

        return response()->json(['data' => new FeedUpdateResource($this->refreshed($reply))], 201);
    }

    /**
     * POST /api/feed/updates/{id}/schedule
     *
     * Same as {@see reply()}, except the reply stays invisible everywhere
     * (`scheduled_at` in the future) until
     * {@see FeedService::publishDue()} publishes it.
     */
    public function schedule(Request $request, string $id): JsonResponse
    {
        $comment = $this->resolveComment($id);
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'mentioned_user_ids' => ['sometimes', 'array'],
            'mentioned_user_ids.*' => ['integer', 'exists:users,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        $reply = $this->createReply($comment, $validated, $validated['scheduled_at']);

        return response()->json(['data' => new FeedUpdateResource($this->refreshed($reply))], 201);
    }

    /**
     * Closure form of the "all" tab's reach (the viewer authored it, was
     * mentioned in it, or authored the thread it replies to), for passing
     * straight into `where()`/`tap()` — sidesteps threading a `Builder<T>`
     * generic through a shared helper for two differently-typed models.
     */
    private function allScopeClosure(User $user): \Closure
    {
        return function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhereHas('mentions', fn (Builder $mq) => $mq->where('user_id', $user->id))
                ->orWhereHas('parent', fn (Builder $pq) => $pq->where('user_id', $user->id));
        };
    }

    /**
     * @param  class-string<BoardItemComment>|class-string<BoardComment>  $model_class
     * @return Builder<BoardItemComment>|Builder<BoardComment>
     */
    private function scopedQuery(string $model_class, User $user, string $tab, ?int $board_id): Builder
    {
        $is_item = $model_class === BoardItemComment::class;
        $board_relation = $is_item ? 'item.board' : 'board';
        $workspace_ids = $user->workspaces()->pluck('workspaces.id');

        $query = $model_class::query()->visibleNow();

        match ($tab) {
            'mentioned' => $query->whereHas('mentions', fn (Builder $q) => $q->where('user_id', $user->id)),
            'bookmarked' => $query->whereHas('bookmarks', fn (Builder $q) => $q->where('user_id', $user->id)),
            'account' => null,
            default => $query->where($this->allScopeClosure($user)),
        };

        $query->whereHas($board_relation, function (Builder $q) use ($workspace_ids, $board_id) {
            $q->whereIn('workspace_id', $workspace_ids);
            if ($board_id) {
                $q->where('id', $board_id);
            }
        });

        return $query;
    }

    /**
     * Resolves the opaque feed id ("ic-{id}" | "bc-{id}") back to its model —
     * disambiguates the two source tables without a shared id space.
     */
    private function resolveComment(string $id): BoardItemComment|BoardComment
    {
        if (str_starts_with($id, 'ic-')) {
            return BoardItemComment::findOrFail((int) substr($id, 3));
        }

        if (str_starts_with($id, 'bc-')) {
            return BoardComment::findOrFail((int) substr($id, 3));
        }

        abort(404);
    }

    private function boardFor(BoardItemComment|BoardComment $comment): WorkspaceNavigationItem
    {
        return $comment instanceof BoardItemComment ? $comment->item->board : $comment->board;
    }

    /**
     * @return array<int, string>
     */
    private function itemLoads(): array
    {
        return ['author', 'mentions', 'bookmarks', 'views', 'item.board.parent'];
    }

    /**
     * @return array<int, string>
     */
    private function boardLoads(): array
    {
        return ['author', 'mentions', 'bookmarks', 'views', 'board.parent'];
    }

    /**
     * @param  array{body: string, mentioned_user_ids?: array<int, int>}  $validated
     */
    private function createReply(BoardItemComment|BoardComment $comment, array $validated, ?string $scheduled_at): BoardItemComment|BoardComment
    {
        $parent_id = $comment->parent_id ?? $comment->id;
        $model_class = $comment::class;

        $reply = $model_class::create([
            ...($comment instanceof BoardItemComment ? ['item_id' => $comment->item_id] : ['board_id' => $comment->board_id]),
            'parent_id' => $parent_id,
            'user_id' => request()->user()?->id,
            'body' => $validated['body'],
            'scheduled_at' => $scheduled_at,
        ]);

        $mentioned_user_ids = collect($validated['mentioned_user_ids'] ?? [])->unique();
        if ($mentioned_user_ids->isNotEmpty()) {
            $reply->mentions()->createMany($mentioned_user_ids->map(fn ($user_id) => ['user_id' => $user_id])->all());
        }

        return $reply;
    }

    /**
     * @param  Collection<int, int>  $mentioned_user_ids
     */
    private function notifyAndBroadcastReply(BoardItemComment|BoardComment $parent, BoardItemComment|BoardComment $reply, Collection $mentioned_user_ids, User $actor): void
    {
        $board = $this->boardFor($reply);
        $is_item = $reply instanceof BoardItemComment;
        $link = $is_item ? "/boards/{$board->id}/pulses/{$reply->item_id}" : "/boards/{$board->id}";
        $reply_class = $reply::class;
        $thread_author = $reply_class::find($reply->parent_id)?->author;

        if ($thread_author && $thread_author->id !== $actor->id) {
            $this->notification_service->notify(
                recipient: $thread_author,
                actor: $actor,
                type: $is_item ? Notification::TYPE_REPLIED_THREAD : Notification::TYPE_REPLIED_UPDATE,
                board: $board,
                action_label: 'Replied to your update',
                action_target: $is_item ? sprintf('on "%s"', $reply->item->name) : sprintf('on the Board "%s"', $board->label),
                link: $link,
            );
        }

        foreach ($mentioned_user_ids as $mentioned_user_id) {
            if ($mentioned_user = User::find($mentioned_user_id)) {
                $this->notification_service->notify(
                    recipient: $mentioned_user,
                    actor: $actor,
                    type: Notification::TYPE_MENTIONED,
                    board: $board,
                    action_label: 'Mentioned you',
                    action_target: $is_item ? sprintf('in a comment on "%s"', $reply->item->name) : sprintf('in a comment on the Board "%s"', $board->label),
                    link: $link,
                );
            }
        }

        $this->feed_service->broadcastUpdate($this->refreshed($reply), $board, $thread_author);
    }

    /**
     * Reloads `$comment` with its feed-resource eager loads, keeping the
     * union narrowed per branch (rather than through `Model::fresh()`'s
     * `static` return type, which collapses to the base `Model` type when
     * the receiver is itself a `BoardItemComment|BoardComment` union).
     * Falls back to the original instance on the (very unlikely) chance the
     * row was deleted between creation and this reload.
     */
    private function refreshed(BoardItemComment|BoardComment $comment): BoardItemComment|BoardComment
    {
        if ($comment instanceof BoardItemComment) {
            return $comment->fresh($this->itemLoads()) ?? $comment;
        }

        return $comment->fresh($this->boardLoads()) ?? $comment;
    }
}
