<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class NotificationController extends Controller
{
    private const PAGE_SIZE = 20;

    private const MAX_PAGE_SIZE = 50;

    private const MAX_SNOOZE_DAYS = 365;

    private const MAX_BULK_IDS = 100;

    /**
     * GET /api/notifications?tab=&board_id=&actor_id=&unread=&q=&cursor=&limit=
     *
     * One cursor-paginated page of the current user's notifications, newest
     * first. Every filter of the bell drawer (tab, board, person, unread only,
     * search) is applied here so paging never has to fetch rows it will hide.
     * Snoozed and dismissed notifications are left out.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tab' => ['sometimes', 'string', 'in:all,'.implode(',', array_keys(Notification::CATEGORY_TYPES))],
            'board_id' => ['sometimes', 'integer'],
            'actor_id' => ['sometimes', 'integer'],
            'unread' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE_SIZE],
        ]);

        $page = $request->user()->notifications()
            ->visible()
            ->inCategory($validated['tab'] ?? 'all')
            ->when($validated['board_id'] ?? null, fn (Builder $query, int $board_id) => $query->where('board_id', $board_id))
            ->when($validated['actor_id'] ?? null, fn (Builder $query, int $actor_id) => $query->where('actor_id', $actor_id))
            ->when($request->boolean('unread'), fn (Builder $query) => $query->unread())
            ->when(trim((string) ($validated['q'] ?? '')) !== '', fn (Builder $query) => $this->applySearch($query, trim($validated['q'])))
            ->with(['actor', 'board'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($validated['limit'] ?? self::PAGE_SIZE);

        return response()->json([
            'data' => NotificationResource::collection($page->items()),
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    /**
     * GET /api/notifications/filters
     *
     * The boards and people that appear in the current user's notifications,
     * so the drawer's board and person filters only offer options that can
     * actually match something.
     */
    public function filters(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()->visible();

        $boards = WorkspaceNavigationItem::query()
            ->whereIn('id', (clone $notifications)->whereNotNull('board_id')->select('board_id'))
            ->orderBy('label')
            ->get(['id', 'label'])
            ->map(fn (WorkspaceNavigationItem $board) => ['id' => $board->id, 'name' => $board->label])
            ->values();

        $actors = User::query()
            ->whereIn('id', (clone $notifications)->whereNotNull('actor_id')->select('actor_id'))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn (User $actor) => ['id' => $actor->id, 'name' => $actor->full_name])
            ->values();

        return response()->json(['data' => ['boards' => $boards, 'actors' => $actors]]);
    }

    /**
     * GET /api/notifications/unread-count
     *
     * Powers the bell icon's unread dot.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'unread_count' => $request->user()->notifications()->unread()->visible()->count(),
            ],
        ]);
    }

    /**
     * PATCH /api/notifications/{notification}/read
     */
    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 403);

        $notification->markAsRead();

        return response()->json([
            'data' => [
                'id' => (string) $notification->id,
                'is_unread' => false,
            ],
        ]);
    }

    /**
     * PATCH /api/notifications/{notification}/unread
     *
     * "Mark as unread", so a notification the user opened can be flagged to
     * come back to later.
     */
    public function markAsUnread(Request $request, Notification $notification): JsonResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 403);

        $notification->markAsUnread();

        return response()->json([
            'data' => [
                'id' => (string) $notification->id,
                'is_unread' => true,
            ],
        ]);
    }

    /**
     * PATCH /api/notifications/read-all
     *
     * Marks every one of the current user's unread, visible notifications as
     * read, the bell drawer's "Mark all as read".
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->notifications()
            ->unread()
            ->visible()
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    /**
     * POST /api/notifications/bulk
     *
     * Applies one action (`read`, `unread` or `dismiss`) to several of the
     * current user's notifications at once, the bell drawer's multi-select
     * toolbar. Ids that are not the caller's are ignored rather than rejected,
     * so a stale selection never fails the whole request. Responds with the ids
     * that were changed and the fresh unread count.
     */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:read,unread,dismiss'],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK_IDS],
            'ids.*' => ['integer'],
        ]);

        $notifications = $request->user()->notifications()
            ->whereIn('id', $validated['ids'])
            ->whereNull('dismissed_at');

        $affected_ids = (clone $notifications)->pluck('id');

        match ($validated['action']) {
            'read' => $notifications->update(['is_read' => true, 'read_at' => now()]),
            'unread' => $notifications->update(['is_read' => false, 'read_at' => null]),
            'dismiss' => $notifications->update(['dismissed_at' => now()]),
        };

        return response()->json([
            'data' => [
                'ids' => $affected_ids->map(fn ($id) => (string) $id)->values(),
                'unread_count' => $request->user()->notifications()->unread()->visible()->count(),
            ],
        ]);
    }

    /**
     * PATCH /api/notifications/{notification}/snooze
     *
     * "Remind me later": hides the notification until `snoozed_until`, when
     * `notifications:wake-snoozed` resurfaces it as unread.
     */
    public function snooze(Request $request, Notification $notification): JsonResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'snoozed_until' => ['required', 'date', 'after:now', 'before:'.now()->addDays(self::MAX_SNOOZE_DAYS)->toDateTimeString()],
        ]);

        $notification->snoozeUntil(Carbon::parse($validated['snoozed_until']));

        return response()->json([
            'data' => [
                'id' => (string) $notification->id,
                'snoozed_until' => $notification->snoozed_until,
            ],
        ]);
    }

    /**
     * DELETE /api/notifications/{notification}/snooze
     *
     * Cancels a pending snooze without waiting for it to elapse.
     */
    public function unsnooze(Request $request, Notification $notification): JsonResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 403);

        $notification->update(['snoozed_until' => null]);

        return response()->json(['message' => 'Notification snooze cancelled.']);
    }

    /**
     * DELETE /api/notifications/{notification}
     *
     * Soft-dismisses a single notification (the bell drawer's per-item "×")
     * — it stops showing up in {@see index()}/{@see unreadCount()} without
     * being hard-deleted.
     */
    public function dismiss(Request $request, Notification $notification): JsonResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 403);

        $notification->update(['dismissed_at' => now()]);

        return response()->json(['message' => 'Notification dismissed.']);
    }

    /**
     * Matches every whitespace-separated term of `$search` against the actor's
     * first or last name, or the board's label, so "ada love" finds Ada Lovelace.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $like = '%'.addcslashes($term, '%_\\').'%';

            $query->where(function (Builder $inner) use ($like) {
                $inner->whereHas('actor', fn (Builder $actor) => $actor
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like))
                    ->orWhereHas('board', fn (Builder $board) => $board->where('label', 'like', $like));
            });
        }

        return $query;
    }
}
