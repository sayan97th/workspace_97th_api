<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceContentItemResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manage Workspace's "Recents" and "Content" tabs — both list the same kind
 * of row the sidebar does (board/doc leaves), just sliced differently: the
 * newest few within one workspace, versus every one across every workspace
 * the user belongs to.
 */
class ContentController extends Controller
{
    private const DEFAULT_RECENT_LIMIT = 10;

    private const MAX_RECENT_LIMIT = 50;

    private const DEFAULT_PER_PAGE = 30;

    private const MAX_PER_PAGE = 100;

    /** "Manage Workspace" is itself a navigation leaf, not real content — never list it. */
    private const MANAGE_WORKSPACE_VIEW_KEY = 'workspace_manage';

    /**
     * GET /api/workspaces/{workspace}/content/recent
     *
     * The most recently created boards/docs within this workspace, at any
     * depth in its navigation tree.
     */
    public function recent(Request $request, Workspace $workspace): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', self::DEFAULT_RECENT_LIMIT), self::MAX_RECENT_LIMIT));

        $items = $workspace->navigationItems()
            ->where('type', WorkspaceNavigationItem::TYPE_LEAF)
            ->notArchived()
            ->with(['creator', 'workspace'])
            ->latest('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => WorkspaceContentItemResource::collection($items),
        ]);
    }

    /**
     * GET /api/content
     *
     * Every board/doc across every workspace the current user belongs to,
     * paginated — the exact same rows their sidebar can navigate to. Accepts
     * the Content tab's "Filter by" facets as array query params:
     * `last_modified[]`, `asset_type[]`, `created_by[]`, `membership[]`.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workspace_ids = $user->workspaces()->pluck('workspaces.id');
        $per_page = max(1, min((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));

        $query = WorkspaceNavigationItem::query()
            ->whereIn('workspace_id', $workspace_ids)
            ->where('type', WorkspaceNavigationItem::TYPE_LEAF)
            ->notArchived()
            ->where(fn (Builder $query) => $query->where('view_key', '!=', self::MANAGE_WORKSPACE_VIEW_KEY)
                ->orWhereNull('view_key'))
            ->with(['creator', 'workspace']);

        $this->applyFilters($query, $request, $user);

        $paginator = $query->orderBy('label')->paginate($per_page);

        return response()->json([
            'data' => WorkspaceContentItemResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /api/content/creators
     *
     * Every distinct creator behind the current user's accessible content,
     * with their content count — populates the "Created by" filter list.
     * Independent of any active filters, so the list doesn't shrink as the
     * user narrows the table down.
     */
    public function creators(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workspace_ids = $user->workspaces()->pluck('workspaces.id');

        $counts = WorkspaceNavigationItem::query()
            ->whereIn('workspace_id', $workspace_ids)
            ->where('type', WorkspaceNavigationItem::TYPE_LEAF)
            ->where(function (Builder $query) {
                $query->where('view_key', '!=', self::MANAGE_WORKSPACE_VIEW_KEY)
                    ->orWhereNull('view_key');
            })
            ->whereNotNull('created_by_id')
            ->selectRaw('created_by_id, count(*) as content_count')
            ->groupBy('created_by_id')
            ->pluck('content_count', 'created_by_id');

        $creators = User::query()
            ->whereIn('id', $counts->keys())
            ->get()
            ->map(fn (User $creator) => [
                'id' => $creator->id,
                'full_name' => $creator->full_name,
                'profile_photo_url' => $creator->profile_photo_url,
                'content_count' => $counts->get($creator->id, 0),
            ])
            ->sortByDesc('content_count')
            ->values();

        return response()->json(['data' => $creators]);
    }

    /**
     * Narrows a Content-tab query by the "Filter by" facets, each combined with
     * AND across facets and OR within a facet's own selected values.
     */
    private function applyFilters(Builder $query, Request $request, User $user): void
    {
        $last_modified = $this->arrayParam($request, 'last_modified');
        if ($last_modified !== []) {
            $query->where(function (Builder $query) use ($last_modified) {
                foreach ($last_modified as $bucket) {
                    $cutoff = $this->lastModifiedCutoff($bucket);
                    if ($cutoff !== null) {
                        $query->orWhere('updated_at', '<=', $cutoff);
                    }
                }
            });
        }

        $asset_types = $this->arrayParam($request, 'asset_type');
        if ($asset_types !== []) {
            $view_keys = collect($asset_types)
                ->flatMap(fn (string $type) => WorkspaceNavigationItem::ASSET_TYPE_VIEW_KEYS[$type] ?? [])
                ->unique()
                ->values()
                ->all();

            $query->where(function (Builder $query) use ($view_keys, $asset_types) {
                if ($view_keys !== []) {
                    $query->whereIn('view_key', $view_keys);
                }
                if (in_array(WorkspaceNavigationItem::ASSET_TYPE_BOARD, $asset_types, true)) {
                    $non_board_view_keys = collect(WorkspaceNavigationItem::ASSET_TYPE_VIEW_KEYS)->flatten();
                    $query->orWhere(fn (Builder $query) => $query->whereNotIn('view_key', $non_board_view_keys)
                        ->orWhereNull('view_key'));
                }
            });
        }

        $created_by = collect($this->arrayParam($request, 'created_by'))
            ->map(fn (string $id) => (int) $id)
            ->filter()
            ->all();
        if ($created_by !== []) {
            $query->whereIn('created_by_id', $created_by);
        }

        $membership = $this->arrayParam($request, 'membership');
        if ($membership !== []) {
            $member_workspace_ids = new Collection;

            if (in_array('owner', $membership, true)) {
                $member_workspace_ids = $member_workspace_ids->merge(
                    DB::table('workspace_user')
                        ->where('user_id', $user->id)
                        ->where('role', 'owner')
                        ->pluck('workspace_id')
                );
            }

            if (in_array('member', $membership, true)) {
                $member_workspace_ids = $member_workspace_ids->merge(
                    DB::table('workspace_user')
                        ->where('user_id', $user->id)
                        ->where(fn ($query) => $query->whereNull('role')->orWhereIn('role', ['member', 'collaborator']))
                        ->pluck('workspace_id')
                );
            }

            $query->whereIn('workspace_id', $member_workspace_ids->unique()->values());
        }
    }

    private function lastModifiedCutoff(string $bucket): ?CarbonInterface
    {
        return match ($bucket) {
            '1m' => now()->subMonth(),
            '3m' => now()->subMonths(3),
            '6m' => now()->subMonths(6),
            '1y' => now()->subYear(),
            '2y' => now()->subYears(2),
            default => null,
        };
    }

    /**
     * Reads a `key[]=`-style array query param, dropping empty/non-string values.
     *
     * @return array<int, string>
     */
    private function arrayParam(Request $request, string $key): array
    {
        $value = $request->query($key, []);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($item) => is_string($item) && $item !== ''));
    }
}
