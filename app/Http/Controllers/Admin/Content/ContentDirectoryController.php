<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\BulkBoardActionRequest;
use App\Http\Requests\Admin\Content\BulkReassignBoardsRequest;
use App\Http\Resources\AdminContentBoardResource;
use App\Models\BoardActivityLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardActivityLogger;
use App\Support\Admin\AdminBoardQuery;
use App\Support\Admin\CsvExport;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Administration > Content directory and Administration > Tidy up: every board in the
 * account regardless of workspace membership or privacy, with bulk archive, restore and
 * owner reassignment. Filters are documented on {@see AdminBoardQuery}.
 */
class ContentDirectoryController extends Controller
{
    private const BOARD_TYPE_LABELS = [
        WorkspaceNavigationItem::BOARD_TYPE_MAIN => 'Main',
        WorkspaceNavigationItem::BOARD_TYPE_PRIVATE => 'Private',
        WorkspaceNavigationItem::BOARD_TYPE_SHAREABLE => 'Shareable',
    ];

    public function __construct(private readonly BoardActivityLogger $activity_logger) {}

    /**
     * GET /api/admin/content
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->sortedQuery($request);

        $per_page = max(1, min((int) $request->query('per_page', 25), 100));
        $boards = $query->paginate($per_page);

        return response()->json([
            'data' => AdminContentBoardResource::collection($boards->items()),
            'current_page' => $boards->currentPage(),
            'last_page' => $boards->lastPage(),
            'total' => $boards->total(),
        ]);
    }

    /**
     * GET /api/admin/content/filter-options
     *
     * The choices for the Workspace and Owner column filters: every workspace, and every
     * person who currently owns at least one board.
     */
    public function filterOptions(): JsonResponse
    {
        $owner_ids = WorkspaceNavigationItem::query()->boards()->whereNotNull('owner_id')->distinct()->pluck('owner_id');

        return response()->json([
            'workspaces' => Workspace::query()->orderBy('name')->get(['id', 'name'])->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
            ])->values(),
            'owners' => User::withTrashed()->whereIn('id', $owner_ids)->orderBy('first_name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'profile_photo_url' => $user->profile_photo_url,
                'is_deactivated' => $user->is_deactivated,
            ])->values(),
        ]);
    }

    /**
     * GET /api/admin/content/export
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->sortedQuery($request);

        AuditLogger::log('content.exported', 'Exported the content directory to CSV.', $request->user());

        $rows = (function () use ($query) {
            foreach ($query->lazy(500) as $board) {
                $last_activity = $board->getAttribute('last_activity_at');

                yield [
                    $board->label,
                    $board->workspace?->name ?? '',
                    $board->owner?->full_name ?? 'No owner',
                    self::BOARD_TYPE_LABELS[$board->board_type ?? WorkspaceNavigationItem::BOARD_TYPE_MAIN] ?? $board->board_type,
                    $board->is_archived ? 'Archived' : 'Active',
                    (int) $board->getAttribute('items_count'),
                    $board->created_at?->format('Y-m-d'),
                    $last_activity ? Carbon::parse($last_activity)->format('Y-m-d H:i') : '',
                ];
            }
        })();

        return CsvExport::download(
            'content-directory-'.now()->format('Y-m-d').'.csv',
            ['Board', 'Workspace', 'Owner', 'Type', 'Status', 'Items', 'Created', 'Last activity'],
            $rows,
        );
    }

    /**
     * POST /api/admin/content/archive
     */
    public function archive(BulkBoardActionRequest $request): JsonResponse
    {
        $boards = $this->boards($request->validated('board_ids'))->where('is_archived', false);

        DB::transaction(function () use ($boards, $request) {
            foreach ($boards as $board) {
                $board->update(['is_archived' => true, 'archived_at' => now()]);
                $this->activity_logger->log($board, $request->user(), BoardActivityLog::ACTION_ARCHIVED, 'Archived the board from Administration');
            }
        });

        return $this->bulkResponse($request, $boards, 'content.archived', 'Archived', 'archived');
    }

    /**
     * POST /api/admin/content/unarchive
     */
    public function unarchive(BulkBoardActionRequest $request): JsonResponse
    {
        $boards = $this->boards($request->validated('board_ids'))->where('is_archived', true);

        DB::transaction(function () use ($boards, $request) {
            foreach ($boards as $board) {
                $board->update(['is_archived' => false, 'archived_at' => null]);
                $this->activity_logger->log($board, $request->user(), BoardActivityLog::ACTION_UNARCHIVED, 'Restored the board from Administration');
            }
        });

        return $this->bulkResponse($request, $boards, 'content.unarchived', 'Restored', 'restored');
    }

    /**
     * POST /api/admin/content/reassign
     */
    public function reassign(BulkReassignBoardsRequest $request): JsonResponse
    {
        $owner = User::query()->findOrFail($request->validated('owner_id'));

        if (! $owner->is_active) {
            return response()->json(['message' => 'Boards cannot be assigned to a deactivated user.'], 422);
        }

        $boards = $this->boards($request->validated('board_ids'))->where('owner_id', '!=', $owner->id);

        DB::transaction(function () use ($boards, $owner) {
            WorkspaceNavigationItem::query()->whereIn('id', $boards->modelKeys())->update(['owner_id' => $owner->id]);
        });

        return $this->bulkResponse($request, $boards, 'content.reassigned', "Reassigned to {$owner->full_name}", 'reassigned', ['owner_id' => $owner->id]);
    }

    /**
     * @return Builder<WorkspaceNavigationItem>
     */
    private function sortedQuery(Request $request)
    {
        $sort_field = (string) $request->query('sort_field', 'last_activity_at');
        $sort_direction = $request->query('sort_direction') === 'asc' ? 'asc' : 'desc';

        $query = AdminBoardQuery::fromRequest($request);
        AdminBoardQuery::applySort(
            $query,
            in_array($sort_field, AdminBoardQuery::ALLOWED_SORT_FIELDS, true) ? $sort_field : 'last_activity_at',
            $sort_direction,
        );

        return $query;
    }

    /**
     * @param  array<int, int>  $board_ids
     * @return Collection<int, WorkspaceNavigationItem>
     */
    private function boards(array $board_ids): Collection
    {
        return WorkspaceNavigationItem::query()->boards()->whereIn('id', $board_ids)->get();
    }

    /**
     * @param  Collection<int, WorkspaceNavigationItem>  $boards
     * @param  array<string, mixed>  $metadata
     */
    private function bulkResponse(Request $request, Collection $boards, string $event, string $verb, string $past_tense, array $metadata = []): JsonResponse
    {
        $count = $boards->count();

        if ($count > 0) {
            AuditLogger::log($event, "{$verb} {$count} board(s) from Administration.", $request->user(), [
                ...$metadata,
                'board_ids' => $boards->modelKeys(),
            ]);
        }

        return response()->json([
            'message' => $count === 1 ? "1 board {$past_tense}." : "{$count} boards {$past_tense}.",
            'updated_count' => $count,
            'skipped_count' => count($request->input('board_ids', [])) - $count,
        ]);
    }
}
