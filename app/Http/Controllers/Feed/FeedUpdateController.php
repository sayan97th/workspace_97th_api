<?php

namespace App\Http\Controllers\Feed;

use App\Exports\FeedUpdatesExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\FeedUpdateResource;
use App\Models\BoardComment;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\FeedFollow;
use App\Models\FeedSavedView;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardItemActivityService;
use App\Services\Board\ScheduledCommentService;
use App\Services\Feed\FeedService;
use App\Services\Notification\NotificationService;
use App\Support\BoardEditGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
    private const PAGE_SIZE = 20;

    private const MAX_PAGE_SIZE = 50;

    /** Pinned updates are always shown first, so the first page loads at most this many. */
    private const MAX_PINNED = 50;

    /** The most updates one Excel export holds, the newest ones win. */
    private const MAX_EXPORT_ROWS = 5000;

    /** Validation for the filters shared by the list, "mark all as read" and saved views. */
    private const FILTER_RULES = [
        'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        'author_id' => ['sometimes', 'nullable', 'integer'],
        'kind' => ['sometimes', 'nullable', 'string', 'in:updates,replies'],
        'from' => ['sometimes', 'nullable', 'date'],
        'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
        'unread' => ['sometimes', 'boolean'],
    ];

    /** Every tab the feed can list, see {@see index()}. */
    private const TABS = 'all,mentioned,bookmarked,account,following,pinned,scheduled';

    /** Tie-break order between the two comment tables when two rows share a `created_at`. */
    private const KIND_RANK = ['ic' => 2, 'bc' => 1];

    public function __construct(
        private readonly NotificationService $notification_service,
        private readonly FeedService $feed_service,
        private readonly BoardItemActivityService $activity_service,
    ) {}

    /**
     * GET /api/feed/updates?tab=all|mentioned|bookmarked|account|following|pinned|scheduled&board_id=&cursor=&limit=
     *   &q=&author_id=&kind=updates|replies&from=&to=&unread=
     *
     * The optional filters narrow the tab's updates: `q` searches the body and
     * the author's name, `kind` keeps top-level updates or replies only,
     * `from`/`to` bound the day it was posted (in the viewer's time zone) and
     * `unread` keeps only what the viewer has not seen yet.
     *
     * One cursor-paginated page of updates (comments + replies, item- and
     * board-level merged) matching the tab, newest first. The first page also
     * carries every pinned update ahead of the newest ones, later pages hold
     * only unpinned updates older than the cursor, so paging never repeats a
     * pinned card. The scheduled and pinned tabs are small and never paginated,
     * `pinned` lists every pinned update in the viewer's workspaces and
     * `following` the updates on the boards and items the viewer follows.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tab' => ['sometimes', 'string', 'in:'.self::TABS],
            'board_id' => ['sometimes', 'integer'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE_SIZE],
            ...self::FILTER_RULES,
        ]);

        $tab = $validated['tab'] ?? 'all';
        $board_id = $request->integer('board_id') ?: null;
        $user = $request->user();
        $filters = $this->readFilters($request);

        if ($tab === 'scheduled') {
            return $this->respondWithPage($this->scheduledUpdates($user, $board_id), null);
        }

        if ($tab === 'pinned') {
            return $this->respondWithPage($this->fetchUpdates($user, $tab, $board_id, true, null, self::MAX_PINNED, $filters), null);
        }

        $limit = $validated['limit'] ?? self::PAGE_SIZE;
        $cursor = $this->decodeCursor($validated['cursor'] ?? null);

        $pinned = $cursor === null ? $this->fetchUpdates($user, $tab, $board_id, true, null, self::MAX_PINNED, $filters) : collect();
        $rows = $this->fetchUpdates($user, $tab, $board_id, false, $cursor, $limit + 1, $filters);

        $has_more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return $this->respondWithPage(
            $pinned->concat($rows),
            $has_more && $rows->isNotEmpty() ? $this->encodeCursor($rows->last()) : null,
        );
    }

    /**
     * GET /api/feed/updates/export?tab=&board_id=&q=&author_id=&kind=&from=&to=&unread=
     *
     * The feed's "Export to Excel": every update of the tab and filters the
     * viewer has open (the newest {@see MAX_EXPORT_ROWS} at most), pinned ones
     * first, as an .xlsx workbook. Takes the same parameters as {@see index()}
     * minus the paging ones.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'tab' => ['sometimes', 'string', 'in:'.self::TABS],
            'board_id' => ['sometimes', 'integer'],
            ...self::FILTER_RULES,
        ]);

        $tab = $validated['tab'] ?? 'all';
        $board_id = $request->integer('board_id') ?: null;
        $user = $request->user();
        $filters = $this->readFilters($request);

        $updates = $tab === 'scheduled'
            ? $this->scheduledUpdates($user, $board_id)
            : $this->fetchUpdates($user, $tab, $board_id, true, null, self::MAX_PINNED, $filters)
                ->concat($this->fetchUpdates($user, $tab, $board_id, false, null, self::MAX_EXPORT_ROWS, $filters));

        return Excel::download(new FeedUpdatesExport($updates->values()), 'update_feed_'.now()->format('Y_m_d').'.xlsx');
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

        $unread_by_board = $this->unreadCountsByBoard($user);

        $boards = WorkspaceNavigationItem::whereIn('id', array_keys($combined))->get(['id', 'label'])->keyBy('id');

        $rows = collect($combined)
            ->map(function ($count, $board_id) use ($boards, $unread_by_board) {
                $board = $boards->get($board_id);

                return [
                    'id' => (string) $board_id,
                    'name' => $board !== null ? $board->label : __('Deleted board'),
                    'count' => $count,
                    'unread_count' => $unread_by_board[$board_id] ?? 0,
                ];
            })
            ->sortByDesc('count')
            ->values();

        $all_boards_row = collect([[
            'id' => 'all-boards',
            'name' => 'All boards in my feed',
            'count' => array_sum($combined),
            'unread_count' => array_sum($unread_by_board),
        ]]);

        return response()->json(['data' => $all_boards_row->concat($rows)]);
    }

    /**
     * GET /api/feed/boards/{board}/people
     *
     * The workspace members that can be `@mentioned` in a reply to an update
     * on `{board}`, for the feed card's reply composer (the item drawer gets
     * the same roster from its board's own people list).
     */
    public function people(Request $request, WorkspaceNavigationItem $board): JsonResponse
    {
        abort_unless($request->user()->workspaces()->where('workspaces.id', $board->workspace_id)->exists(), 403);

        // Deactivated accounts stay out: they cannot see the mention, so there is nobody to notify.
        $people = $board->workspace->users()
            ->where('users.is_active', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn (User $person) => [
                'id' => $person->id,
                'name' => $person->full_name,
                'avatar_url' => $person->profile_photo_url,
            ]);

        return response()->json(['data' => $people]);
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
        return response()->json(['data' => ['unread_count' => $this->countUnread($request->user())]]);
    }

    /**
     * GET /api/feed/filters
     *
     * The people who wrote the updates in the viewer's feed, so the person
     * filter only offers authors that can actually match something.
     */
    public function filters(Request $request): JsonResponse
    {
        $user = $request->user();

        $author_ids = collect();
        foreach (['ic' => BoardItemComment::class, 'bc' => BoardComment::class] as $kind => $model_class) {
            $author_ids = $author_ids->concat(
                $this->scopedQuery($model_class, $user, 'all', null)
                    ->whereNotNull('user_id')
                    ->distinct()
                    ->pluck('user_id')
            );
        }

        $authors = User::query()
            ->whereIn('id', $author_ids->unique()->values())
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn (User $author) => ['id' => $author->id, 'name' => $author->full_name])
            ->values();

        return response()->json(['data' => ['authors' => $authors]]);
    }

    /**
     * POST /api/feed/updates/read-all?tab=&board_id=&q=&author_id=&kind=&from=&to=
     *
     * Marks as seen every unread update the given tab, board and filters
     * match, the feed's "Mark all as read". Only the viewer's own seen state
     * changes. Responds with how many were marked and the fresh unread count.
     */
    public function markAllSeen(Request $request): JsonResponse
    {
        $request->validate([
            'tab' => ['sometimes', 'string', 'in:all,mentioned,bookmarked,account,following,pinned'],
            'board_id' => ['sometimes', 'nullable', 'integer'],
            ...self::FILTER_RULES,
        ]);

        $user = $request->user();
        $tab = $request->input('tab', 'all');
        $board_id = $request->integer('board_id') ?: null;
        $filters = [...$this->readFilters($request), 'unread' => true];
        $marked = 0;

        foreach ([BoardItemComment::class, BoardComment::class] as $model_class) {
            $view_table = (new ($model_class.'View'))->getTable();

            $this->scopedQuery($model_class, $user, $tab, $board_id, $filters)
                ->select('id')
                ->chunkById(500, function ($comments) use ($user, $view_table, &$marked) {
                    $now = now();
                    $marked += DB::table($view_table)->insertOrIgnore(
                        $comments->map(fn ($comment) => [
                            'comment_id' => $comment->id,
                            'user_id' => $user->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all()
                    );
                });
        }

        return response()->json(['data' => ['marked_count' => $marked, 'unread_count' => $this->countUnread($user)]]);
    }

    /**
     * DELETE /api/feed/updates/{id}/seen
     *
     * "Mark as unread": brings the update back as unread for the viewer, so
     * it can be revisited later. A no-op on the viewer's own posts, which are
     * never unread.
     */
    public function markUnseen(Request $request, string $id): JsonResponse
    {
        $comment = $this->resolveComment($id);
        $comment->views()->where('user_id', $request->user()->id)->delete();

        return response()->json(['data' => new FeedUpdateResource($this->refreshed($comment))]);
    }

    /**
     * GET /api/feed/saved-views
     */
    public function savedViews(Request $request): JsonResponse
    {
        $views = $request->user()->feedSavedViews()->orderBy('id')->get();

        return response()->json(['data' => $views->map(fn (FeedSavedView $view) => $this->savedViewPayload($view))->values()]);
    }

    /**
     * POST /api/feed/saved-views
     *
     * Saves the given tab, board and filters under a name.
     */
    public function storeSavedView(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'tab' => ['sometimes', 'string', 'in:'.self::TABS],
            'board_id' => ['sometimes', 'nullable', 'integer'],
            ...self::FILTER_RULES,
        ]);

        abort_if(
            $request->user()->feedSavedViews()->count() >= FeedSavedView::MAX_PER_USER,
            422,
            'You can keep up to '.FeedSavedView::MAX_PER_USER.' saved views.'
        );

        $view = $request->user()->feedSavedViews()->create([
            'name' => trim($request->string('name')->toString()),
            'filters' => [
                'tab' => $request->input('tab', 'all'),
                'board_id' => $request->integer('board_id') ?: null,
                ...$this->readFilters($request),
            ],
        ]);

        return response()->json(['data' => $this->savedViewPayload($view)], 201);
    }

    /**
     * DELETE /api/feed/saved-views/{saved_view}
     */
    public function destroySavedView(Request $request, FeedSavedView $saved_view): JsonResponse
    {
        abort_if($saved_view->user_id !== $request->user()->id, 403);

        $saved_view->delete();

        return response()->json(['message' => 'Saved view deleted.']);
    }

    /**
     * GET /api/feed/follows
     *
     * The boards and items the viewer follows, for the Following tab's header.
     */
    public function follows(Request $request): JsonResponse
    {
        $follows = $this->followIds($request->user());

        $boards = WorkspaceNavigationItem::query()
            ->whereIn('id', $follows['boards'])
            ->orderBy('label')
            ->get(['id', 'label'])
            ->map(fn (WorkspaceNavigationItem $board) => ['id' => $board->id, 'name' => $board->label])
            ->values();

        $items = BoardItem::query()
            ->whereIn('id', $follows['items'])
            ->with('board:id,label')
            ->orderBy('name')
            ->get(['id', 'name', 'board_id'])
            ->map(fn (BoardItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'board_id' => $item->board_id,
                'board_name' => $item->board?->label,
            ])
            ->values();

        return response()->json(['data' => ['boards' => $boards, 'items' => $items]]);
    }

    /**
     * POST /api/feed/follows  {type: board|item, id}
     *
     * Follows a board or an item the viewer can reach. Following twice is a no-op.
     */
    public function follow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:board,item'],
            'id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $board = $validated['type'] === FeedFollow::TYPE_BOARD
            ? WorkspaceNavigationItem::findOrFail($validated['id'])
            : BoardItem::findOrFail($validated['id'])->board;

        abort_unless($board !== null && $user->workspaces()->where('workspaces.id', $board->workspace_id)->exists(), 403);

        $user->feedFollows()->firstOrCreate([
            'target_type' => $validated['type'],
            'target_id' => $validated['id'],
        ]);

        return response()->json(['data' => ['type' => $validated['type'], 'id' => (int) $validated['id'], 'following' => true]], 201);
    }

    /**
     * DELETE /api/feed/follows/{type}/{id}
     */
    public function unfollow(Request $request, string $type, int $id): JsonResponse
    {
        $request->user()->feedFollows()->where('target_type', $type)->where('target_id', $id)->delete();

        return response()->json(['data' => ['type' => $type, 'id' => $id, 'following' => false]]);
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
     * POST /api/feed/updates/{id}/pin
     *
     * Toggles the pinned state shared with the comment/board discussion
     * threads, a pinned update sorts ahead of the rest of the feed. Only people
     * who can edit the board may pin or unpin.
     */
    public function togglePin(Request $request, string $id): JsonResponse
    {
        $comment = $this->resolveComment($id);
        BoardEditGate::authorize($this->boardFor($comment), $request->user());
        $comment->update(['pinned' => ! $comment->pinned]);

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
        $this->ensureViewerCanAccess($comment, $request->user());
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'mentioned_user_ids' => ['sometimes', 'array', 'max:200'],
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
     * {@see ScheduledCommentService::publishDue()} publishes it.
     */
    public function schedule(Request $request, string $id): JsonResponse
    {
        $comment = $this->resolveComment($id);
        $this->ensureViewerCanAccess($comment, $request->user());
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'mentioned_user_ids' => ['sometimes', 'array', 'max:200'],
            'mentioned_user_ids.*' => ['integer', 'exists:users,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        $reply = $this->createReply($comment, $validated, $validated['scheduled_at']);

        return response()->json(['data' => new FeedUpdateResource($this->refreshed($reply))], 201);
    }

    /**
     * @param  Collection<int, BoardItemComment|BoardComment>  $comments
     */
    private function respondWithPage(Collection $comments, ?string $next_cursor): JsonResponse
    {
        $follows = $this->followIds(request()->user());
        $activity = $this->activity_service->forUpdates($comments->filter(fn ($comment) => $comment instanceof BoardItemComment));

        return response()->json([
            'data' => $comments->map(function ($comment) use ($follows, $activity) {
                $resource = (new FeedUpdateResource($comment))->withFollows($follows['items'], $follows['boards']);

                return $comment instanceof BoardItemComment && isset($activity[$comment->id])
                    ? $resource->withActivity(FeedUpdateResource::activityPayload($activity[$comment->id]))
                    : $resource;
            })->values(),
            'meta' => ['next_cursor' => $next_cursor, 'has_more' => $next_cursor !== null],
        ]);
    }

    /**
     * Every update the viewer scheduled for later, soonest first, from both
     * comment tables.
     *
     * @return Collection<int, BoardItemComment|BoardComment>
     */
    private function scheduledUpdates(User $user, ?int $board_id): Collection
    {
        $item_comments = BoardItemComment::query()->scheduledBy($user->id)
            ->when($board_id, fn (Builder $q) => $q->whereHas('item.board', fn (Builder $bq) => $bq->where('id', $board_id)))
            ->with($this->itemLoads())->get();
        $board_comments = BoardComment::query()->scheduledBy($user->id)
            ->when($board_id, fn (Builder $q) => $q->whereHas('board', fn (Builder $bq) => $bq->where('id', $board_id)))
            ->with($this->boardLoads())->get();

        return $item_comments->concat($board_comments)->sortByDesc('created_at')->values();
    }

    /**
     * The next `$take` updates of the requested tab from both comment tables,
     * merged and ordered newest first (`created_at`, then table, then id, the
     * same order {@see decodeCursor()} resumes from).
     *
     * @param  array{timestamp: int, kind: string, id: int}|null  $cursor
     * @param  array<string, mixed>  $filters
     * @return Collection<int, BoardItemComment|BoardComment>
     */
    private function fetchUpdates(User $user, string $tab, ?int $board_id, bool $pinned, ?array $cursor, int $take, array $filters = []): Collection
    {
        $merged = collect();

        foreach (['ic' => BoardItemComment::class, 'bc' => BoardComment::class] as $kind => $model_class) {
            $rows = $this->scopedQuery($model_class, $user, $tab, $board_id, $filters)
                ->where('pinned', $pinned)
                ->when($cursor, fn (Builder $q) => $this->applyCursor($q, $kind, $cursor))
                ->with($kind === 'ic' ? $this->itemLoads() : $this->boardLoads())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($take)
                ->get();

            $merged = $merged->concat($rows);
        }

        return $merged
            ->sort(fn ($a, $b) => $this->sortKey($b) <=> $this->sortKey($a))
            ->take($take)
            ->values();
    }

    /**
     * Keeps only the rows that sort after `$cursor` in the (created_at, table,
     * id) order, so a page boundary never skips or repeats a row even when two
     * updates share the same second.
     *
     * @param  Builder<BoardItemComment>|Builder<BoardComment>  $query
     * @param  array{timestamp: int, kind: string, id: int}  $cursor
     * @return Builder<BoardItemComment>|Builder<BoardComment>
     */
    private function applyCursor(Builder $query, string $kind, array $cursor): Builder
    {
        $cursor_time = Carbon::createFromTimestamp($cursor['timestamp'], config('app.timezone'));
        $rank = self::KIND_RANK[$kind];
        $cursor_rank = self::KIND_RANK[$cursor['kind']];

        return $query->where(function (Builder $q) use ($cursor_time, $rank, $cursor_rank, $cursor) {
            $q->where('created_at', '<', $cursor_time);

            if ($rank < $cursor_rank) {
                $q->orWhere('created_at', $cursor_time);
            } elseif ($rank === $cursor_rank) {
                $q->orWhere(fn (Builder $tie) => $tie->where('created_at', $cursor_time)->where('id', '<', $cursor['id']));
            }
        });
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function sortKey(BoardItemComment|BoardComment $comment): array
    {
        $kind = $comment instanceof BoardItemComment ? 'ic' : 'bc';

        return [$comment->created_at?->getTimestamp() ?? 0, self::KIND_RANK[$kind], $comment->id];
    }

    private function encodeCursor(BoardItemComment|BoardComment $comment): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            'timestamp' => $comment->created_at?->getTimestamp() ?? 0,
            'kind' => $comment instanceof BoardItemComment ? 'ic' : 'bc',
            'id' => $comment->id,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return array{timestamp: int, kind: string, id: int}|null
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        abort_unless(
            is_array($decoded)
                && isset($decoded['timestamp'], $decoded['id'], $decoded['kind'])
                && is_int($decoded['timestamp'])
                && is_int($decoded['id'])
                && isset(self::KIND_RANK[$decoded['kind']]),
            422,
            'The feed cursor is invalid.'
        );

        return $decoded;
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
     * @param  array<string, mixed>  $filters
     * @return Builder<BoardItemComment>|Builder<BoardComment>
     */
    private function scopedQuery(string $model_class, User $user, string $tab, ?int $board_id, array $filters = []): Builder
    {
        $is_item = $model_class === BoardItemComment::class;
        $board_relation = $is_item ? 'item.board' : 'board';
        $workspace_ids = $user->workspaces()->pluck('workspaces.id');

        $query = $model_class::query()->visibleNow();

        match ($tab) {
            'mentioned' => $query->whereHas('mentions', fn (Builder $q) => $q->where('user_id', $user->id)),
            'bookmarked' => $query->whereHas('bookmarks', fn (Builder $q) => $q->where('user_id', $user->id)),
            'following' => $this->applyFollowing($query, $is_item, $user),
            'account', 'pinned' => null,
            default => $query->where($this->allScopeClosure($user)),
        };

        $query->whereHas($board_relation, function (Builder $q) use ($workspace_ids, $board_id) {
            $q->whereIn('workspace_id', $workspace_ids);
            if ($board_id) {
                $q->where('id', $board_id);
            }
        });

        return $this->applyFilters($query, $filters, $user);
    }

    /**
     * The board and item ids the viewer follows. Read once per request and kept
     * on the request itself, since a controller instance can outlive a request.
     *
     * @return array{boards: array<int, int>, items: array<int, int>}
     */
    private function followIds(User $user): array
    {
        $request = request();

        if (! $request->attributes->has('feed_follow_ids')) {
            $rows = $user->feedFollows()->get(['target_type', 'target_id']);

            $request->attributes->set('feed_follow_ids', [
                'boards' => $rows->where('target_type', FeedFollow::TYPE_BOARD)->pluck('target_id')->map(fn ($id) => (int) $id)->values()->all(),
                'items' => $rows->where('target_type', FeedFollow::TYPE_ITEM)->pluck('target_id')->map(fn ($id) => (int) $id)->values()->all(),
            ]);
        }

        return $request->attributes->get('feed_follow_ids');
    }

    /**
     * Keeps the updates on a followed board (its own discussion and every item
     * on it) or a followed item. Nothing followed means nothing matches.
     *
     * @param  Builder<BoardItemComment>|Builder<BoardComment>  $query
     * @return Builder<BoardItemComment>|Builder<BoardComment>
     */
    private function applyFollowing(Builder $query, bool $is_item, User $user): Builder
    {
        $follows = $this->followIds($user);

        if ($is_item) {
            return $query->whereHas('item', fn (Builder $item) => $item->where(fn (Builder $followed) => $followed
                ->whereIn('board_items.id', $follows['items'])
                ->orWhereIn('board_items.board_id', $follows['boards'])));
        }

        return $query->whereIn('board_id', $follows['boards']);
    }

    /**
     * Reads the optional feed filters off the request in the shape
     * {@see applyFilters()} expects, empty values normalized to `null`/`false`.
     *
     * @return array{q: string, author_id: int|null, kind: string|null, from: string|null, to: string|null, unread: bool}
     */
    private function readFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'author_id' => $request->integer('author_id') ?: null,
            'kind' => $request->input('kind') ?: null,
            'from' => $request->input('from') ?: null,
            'to' => $request->input('to') ?: null,
            'unread' => $request->boolean('unread'),
        ];
    }

    /**
     * Narrows a feed query by the search text, author, kind (updates or
     * replies), posted-on day range and unread state. Days are read in the
     * viewer's own time zone, then converted to the one the rows are stored in.
     *
     * @param  Builder<BoardItemComment>|Builder<BoardComment>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<BoardItemComment>|Builder<BoardComment>
     */
    private function applyFilters(Builder $query, array $filters, User $user): Builder
    {
        $table = $query->getModel()->getTable();
        $viewer_timezone = $user->timezone ?: config('app.timezone');

        foreach (preg_split('/\s+/', (string) ($filters['q'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            $like = '%'.addcslashes($term, '%_\\').'%';

            $query->where(fn (Builder $inner) => $inner
                ->where("{$table}.body", 'like', $like)
                ->orWhereHas('author', fn (Builder $author) => $author
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)));
        }

        if (! empty($filters['author_id'])) {
            $query->where("{$table}.user_id", $filters['author_id']);
        }

        if (($filters['kind'] ?? null) === 'updates') {
            $query->whereNull("{$table}.parent_id");
        } elseif (($filters['kind'] ?? null) === 'replies') {
            $query->whereNotNull("{$table}.parent_id");
        }

        if (! empty($filters['from'])) {
            $query->where("{$table}.created_at", '>=', Carbon::parse($filters['from'], $viewer_timezone)->startOfDay()->setTimezone(config('app.timezone')));
        }

        if (! empty($filters['to'])) {
            $query->where("{$table}.created_at", '<=', Carbon::parse($filters['to'], $viewer_timezone)->endOfDay()->setTimezone(config('app.timezone')));
        }

        if (! empty($filters['unread'])) {
            $query->where("{$table}.user_id", '!=', $user->id)
                ->whereDoesntHave('views', fn (Builder $views) => $views->where('user_id', $user->id));
        }

        return $query;
    }

    /**
     * How many updates the viewer has not seen and that concern them (they
     * were mentioned, or it replies to their own update), over the same reach
     * as the "all" tab. Own posts are never unread.
     */
    private function countUnread(User $user): int
    {
        $workspace_ids = $user->workspaces()->pluck('workspaces.id');
        $count = 0;

        foreach ([BoardItemComment::class, BoardComment::class] as $model_class) {
            $count += $this->concernedUnreadQuery($model_class, $user, $workspace_ids)->count();
        }

        return $count;
    }

    /**
     * {@see countUnread()} split by board, keyed by board id, for the
     * sidebar's per-board unread badges.
     *
     * @return array<int, int>
     */
    private function unreadCountsByBoard(User $user): array
    {
        $workspace_ids = $user->workspaces()->pluck('workspaces.id');
        $counts = [];

        $item_counts = $this->concernedUnreadQuery(BoardItemComment::class, $user, $workspace_ids)
            ->join('board_items', 'board_item_comments.item_id', '=', 'board_items.id')
            ->selectRaw('board_items.board_id as feed_board_id, count(*) as feed_count')
            ->groupBy('feed_board_id')
            ->pluck('feed_count', 'feed_board_id');

        $board_counts = $this->concernedUnreadQuery(BoardComment::class, $user, $workspace_ids)
            ->selectRaw('board_id as feed_board_id, count(*) as feed_count')
            ->groupBy('feed_board_id')
            ->pluck('feed_count', 'feed_board_id');

        foreach ([$item_counts->all(), $board_counts->all()] as $rows) {
            foreach ($rows as $board_id => $count) {
                $counts[$board_id] = ($counts[$board_id] ?? 0) + (int) $count;
            }
        }

        return $counts;
    }

    /**
     * @param  class-string<BoardItemComment>|class-string<BoardComment>  $model_class
     * @param  Collection<int, int>  $workspace_ids
     * @return Builder<BoardItemComment>|Builder<BoardComment>
     */
    private function concernedUnreadQuery(string $model_class, User $user, Collection $workspace_ids): Builder
    {
        $is_item = $model_class === BoardItemComment::class;
        $table = (new $model_class)->getTable();

        return $model_class::query()
            ->visibleNow()
            ->where("{$table}.user_id", '!=', $user->id)
            ->where(function (Builder $q) use ($user) {
                $q->whereHas('mentions', fn (Builder $mq) => $mq->where('user_id', $user->id))
                    ->orWhereHas('parent', fn (Builder $pq) => $pq->where('user_id', $user->id));
            })
            ->whereHas($is_item ? 'item.board' : 'board', fn (Builder $q) => $q->whereIn('workspace_id', $workspace_ids))
            ->whereDoesntHave('views', fn (Builder $q) => $q->where('user_id', $user->id));
    }

    /**
     * @return array{id: int, name: string, filters: array<string, mixed>}
     */
    private function savedViewPayload(FeedSavedView $view): array
    {
        return ['id' => $view->id, 'name' => $view->name, 'filters' => $view->filters];
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

    /**
     * The feed only shows updates from the viewer's own workspaces, so replying
     * to one from anywhere else, an inline reply from a notification included,
     * is refused.
     */
    private function ensureViewerCanAccess(BoardItemComment|BoardComment $comment, User $user): void
    {
        abort_unless($user->workspaces()->where('workspaces.id', $this->boardFor($comment)->workspace_id)->exists(), 403);
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
        return ['author', 'mentions.user', 'bookmarks', 'views', 'item.board.parent'];
    }

    /**
     * @return array<int, string>
     */
    private function boardLoads(): array
    {
        return ['author', 'mentions.user', 'bookmarks', 'views', 'board.parent'];
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
                comment: $reply,
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
                    comment: $reply,
                );
            }
        }

        if ($is_item) {
            $this->feed_service->autoFollowItem($reply->item, $mentioned_user_ids->concat([$actor->id]));
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
