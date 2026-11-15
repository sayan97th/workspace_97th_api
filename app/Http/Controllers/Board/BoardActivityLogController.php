<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\BoardActivityFeedRequest;
use App\Http\Resources\BoardActivityLogResource;
use App\Models\BoardActivityLog;
use App\Models\BoardItemComment;
use App\Models\BoardVisit;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardActivityFeed;
use App\Services\Board\BoardActivityUndoer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The board Activity log drawer, monday.com style, with its three tabs:
 * "Activity" (every change made on the board, see {@see BoardActivityFeed},
 * with Undo, see {@see BoardActivityUndoer}), "Last viewed" (who opened the
 * board and when) and "Updates" (the updates posted on the board's items).
 */
class BoardActivityLogController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

    /** Most rows one CSV export holds. */
    private const MAX_EXPORT_ROWS = 5000;

    /** Updates per page of the "Updates" tab. */
    private const UPDATES_PER_PAGE = 20;

    public function __construct(
        private readonly BoardActivityFeed $feed,
        private readonly BoardActivityUndoer $undoer,
    ) {}

    /**
     * GET /api/boards/{item}/activity-log?item_id=
     *
     * The structural events only ({@see BoardActivityLog}), what the item
     * drawer reads for one item. The Activity log drawer reads {@see feed()}.
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $per_page = max(1, min((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));

        // `item_id` narrows the log to one item, what the item drawer shows under its updates.
        $entries = $item->activityLogs()
            ->with('user')
            ->when($request->integer('item_id') > 0, fn ($query) => $query->where('meta->item_id', $request->integer('item_id')))
            ->paginate($per_page);

        return response()->json([
            'data' => BoardActivityLogResource::collection($entries->items()),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    /**
     * GET /api/boards/{item}/activity-log/feed
     *
     * The "Activity" tab: cell changes and structural events in one
     * timeline, newest first. Paged with the `next_cursor` of the previous
     * page, filtered by {@see BoardActivityFeedRequest}.
     */
    public function feed(BoardActivityFeedRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $page = $this->feed->page(
            $item,
            $request->filters(),
            $request->validated('cursor'),
            (int) ($request->validated('per_page') ?? self::DEFAULT_PER_PAGE),
        );

        $is_first_page = ($request->validated('cursor') ?? '') === '';

        return response()->json([
            'data' => $page['entries'],
            'meta' => [
                'next_cursor' => $page['next_cursor'],
                // The filter menus' choices, sent with the first page only.
                'facets' => $is_first_page ? $this->feed->facets($item) : null,
            ],
        ]);
    }

    /**
     * POST /api/boards/{item}/activity-log/{source}/{entry}/undo
     *
     * The "Undo" button of one entry, `source` is `cell` or `board`.
     */
    public function undo(Request $request, WorkspaceNavigationItem $item, string $source, int $entry): JsonResponse
    {
        validator(['source' => $source], ['source' => ['required', Rule::in([BoardActivityFeed::SOURCE_CELL, BoardActivityFeed::SOURCE_BOARD])]])->validate();

        $result = $this->undoer->undo($item, $request->user(), $source, $entry);

        return response()->json($result);
    }

    /**
     * GET /api/boards/{item}/activity-log/viewers
     *
     * The "Last viewed" tab: everyone who opened the board, most recent first.
     */
    public function viewers(WorkspaceNavigationItem $item): JsonResponse
    {
        $visits = BoardVisit::query()
            ->where('board_id', $item->id)
            ->with('user')
            ->orderByDesc('visited_at')
            ->limit(self::MAX_PER_PAGE)
            ->get()
            ->filter(fn (BoardVisit $visit) => $visit->user !== null);

        return response()->json([
            'data' => $visits->map(fn (BoardVisit $visit) => [
                'user' => [
                    'id' => $visit->user->id,
                    'full_name' => $visit->user->full_name,
                    'profile_photo_url' => $visit->user->profile_photo_url,
                    'is_deactivated' => $visit->user->is_deactivated,
                ],
                'visited_at' => $visit->visited_at,
            ])->values()->all(),
        ]);
    }

    /**
     * GET /api/boards/{item}/activity-log/updates?page=
     *
     * The "Updates" tab: the updates posted on the board's items, newest first.
     */
    public function updates(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $page = BoardItemComment::query()
            ->whereNull('parent_id')
            ->visibleNow()
            ->whereHas('item', fn ($query) => $query->where('board_id', $item->id))
            ->with(['author', 'automation:id,name', 'item:id,name,group_id,parent_id', 'item.group:id,name,accent_color'])
            ->withCount(['replies', 'likes'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::UPDATES_PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (BoardItemComment $comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'created_at' => $comment->created_at,
                'edited_at' => $comment->edited_at,
                'replies_count' => $comment->replies_count,
                'likes_count' => $comment->likes_count,
                'is_from_automations' => $comment->isFromAutomations(),
                'automation_name' => $comment->isFromAutomations() ? ($comment->automation?->name ?: 'Automations') : null,
                'author' => $comment->author !== null ? [
                    'id' => $comment->author->id,
                    'full_name' => $comment->author->full_name,
                    'profile_photo_url' => $comment->author->profile_photo_url,
                    'is_deactivated' => $comment->author->is_deactivated,
                ] : null,
                'item' => [
                    'id' => $comment->item->id,
                    'name' => $comment->item->name,
                    'parent_id' => $comment->item->parent_id,
                    'group' => $comment->item->group !== null ? [
                        'id' => $comment->item->group->id,
                        'name' => $comment->item->group->name,
                        'color' => $comment->item->group->accent_color,
                    ] : null,
                ],
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * GET /api/boards/{item}/activity-log/export
     *
     * The log as a CSV file, with the same filters as the list.
     */
    public function export(BoardActivityFeedRequest $request, WorkspaceNavigationItem $item): StreamedResponse
    {
        $filters = $request->filters();
        $file_name = Str::slug($item->label ?: 'board').'-activity-log-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($item, $filters) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Date', 'Person', 'Item', 'Group', 'Column', 'Event', 'Previous value', 'New value']);

            $cursor = null;
            $written = 0;
            do {
                $page = $this->feed->page($item, $filters, $cursor, self::MAX_PER_PAGE);
                foreach ($page['entries'] as $entry) {
                    fputcsv($handle, $this->csvRow($entry));
                    $written++;
                }
                $cursor = $page['next_cursor'];
            } while ($cursor !== null && $written < self::MAX_EXPORT_ROWS);

            fclose($handle);
        }, $file_name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, string>
     */
    private function csvRow(array $entry): array
    {
        $person = $entry['automation']['name'] ?? $entry['user']['full_name'] ?? 'Someone';
        $is_cell = $entry['source'] === BoardActivityFeed::SOURCE_CELL;

        return array_map($this->csvSafe(...), [
            $entry['created_at']?->toIso8601String() ?? '',
            $entry['automation'] !== null ? "Automation: {$person}" : $person,
            $entry['item']['name'] ?? '',
            $entry['item']['group']['name'] ?? ($entry['group']['name'] ?? ''),
            $entry['column']['label'] ?? '',
            $is_cell ? Str::headline($entry['action']) : (string) $entry['description'],
            (string) ($entry['old_value']['text'] ?? ''),
            (string) ($entry['new_value']['text'] ?? ''),
        ]);
    }

    /**
     * Keeps a value from being read as a formula by a spreadsheet app.
     */
    private function csvSafe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'{$value}" : $value;
    }
}
