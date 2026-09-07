<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Board\BoardGroupController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\MoveWorkspaceNavigationItemRequest;
use App\Http\Requests\Workspace\ReorderWorkspaceNavigationItemsRequest;
use App\Http\Requests\Workspace\StoreWorkspaceNavigationItemRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceNavCollapseStateRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceNavigationItemRequest;
use App\Http\Resources\WorkspaceNavigationItemResource;
use App\Models\BoardActivityLog;
use App\Models\Workspace;
use App\Models\WorkspaceNavCollapseState;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardActivityLogger;
use App\Services\Board\BoardDuplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceNavigationItemController extends Controller
{
    public function __construct(
        private readonly BoardDuplicationService $duplication_service,
        private readonly BoardActivityLogger $activity_logger,
    ) {}

    /**
     * GET /api/workspaces/{workspace}/navigation
     *
     * Returns the full navigation tree for the workspace, plus which of its
     * folders the authenticated viewer currently has collapsed (a personal
     * preference — see {@see updateCollapsedState()}), in one payload.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $tree = $workspace->rootNavigationItems()
            ->notArchived()
            ->with(['childrenRecursive', 'creator', 'workspace.owners'])
            ->get();

        $collapsed_group_ids = WorkspaceNavCollapseState::where('user_id', $request->user()?->id)
            ->where('workspace_id', $workspace->id)
            ->value('collapsed_group_ids');

        return response()->json([
            'data' => WorkspaceNavigationItemResource::collection($this->pruneArchived($tree)),
            'collapsed_group_ids' => $collapsed_group_ids ?? [],
        ]);
    }

    /**
     * Recursively drops archived nodes (and, since a node's own subtree is
     * only reachable through it, everything beneath them) from an
     * already-loaded `childrenRecursive` tree — an archived board is hidden
     * from the sidebar/nav tree, not deleted, so this can't be a query-level
     * filter without risking the same `childrenRecursive` relation used
     * structurally elsewhere (move/duplicate/delete) also silently skipping
     * archived descendants.
     *
     * @param  Collection<int, WorkspaceNavigationItem>  $items
     * @return Collection<int, WorkspaceNavigationItem>
     */
    private function pruneArchived(Collection $items): Collection
    {
        return $items->reject(fn (WorkspaceNavigationItem $item) => $item->is_archived)
            ->values()
            ->each(function (WorkspaceNavigationItem $item) {
                if ($item->relationLoaded('childrenRecursive')) {
                    $item->setRelation('childrenRecursive', $this->pruneArchived($item->childrenRecursive));
                }
            });
    }

    /**
     * POST /api/workspaces/{workspace}/navigation
     */
    public function store(StoreWorkspaceNavigationItemRequest $request, Workspace $workspace): JsonResponse
    {
        $validated = $request->validated();
        $parent_id = $validated['parent_id'] ?? null;

        $item = $workspace->navigationItems()->create([
            'parent_id' => $parent_id,
            'type' => $validated['type'],
            'label' => $validated['label'],
            'description' => $validated['description'] ?? null,
            'slug' => $this->uniqueSlug($workspace, $parent_id, $validated['label']),
            'icon' => $validated['icon'] ?? null,
            'view_key' => $validated['view_key'] ?? null,
            'href' => $validated['href'] ?? null,
            'display_style' => $validated['display_style'] ?? null,
            'board_type' => $validated['board_type'] ?? WorkspaceNavigationItem::BOARD_TYPE_MAIN,
            'is_favorite' => $validated['is_favorite'] ?? false,
            'is_priority' => $validated['is_priority'] ?? false,
            'position' => $validated['position'] ?? $this->nextPosition($workspace, $parent_id),
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Navigation item created successfully.',
            'item' => new WorkspaceNavigationItemResource($item->load(['creator', 'workspace.owners'])),
        ], 201);
    }

    /**
     * PUT /api/workspaces/{workspace}/navigation/collapsed-state
     *
     * Saves which of this workspace's sidebar folders the authenticated
     * viewer currently has collapsed — a personal preference, not shared
     * with other workspace members (mirrors
     * {@see BoardGroupController::updateCollapsedState()}).
     * Only the collapsed ids are ever stored/sent, so this stays a small
     * payload regardless of how many folders the workspace has.
     */
    public function updateCollapsedState(UpdateWorkspaceNavCollapseStateRequest $request, Workspace $workspace): JsonResponse
    {
        $group_ids = $workspace->navigationItems()
            ->where('type', WorkspaceNavigationItem::TYPE_GROUP)
            ->pluck('id');

        $collapsed_group_ids = collect($request->validated('collapsed_group_ids'))
            ->filter(fn (int $id) => $group_ids->contains($id))
            ->values()
            ->all();

        $state = WorkspaceNavCollapseState::updateOrCreate(
            ['user_id' => $request->user()?->id, 'workspace_id' => $workspace->id],
            ['collapsed_group_ids' => $collapsed_group_ids],
        );

        return response()->json([
            'message' => 'Collapsed navigation items saved successfully.',
            'collapsed_group_ids' => $state->collapsed_group_ids,
        ]);
    }

    /**
     * PATCH /api/workspaces/{workspace}/navigation/{item}
     */
    public function update(
        UpdateWorkspaceNavigationItemRequest $request,
        Workspace $workspace,
        WorkspaceNavigationItem $item
    ): JsonResponse {
        $this->ensureItemBelongsToWorkspace($workspace, $item);

        $validated = $request->validated();
        $previous_label = $item->label;
        $previous_board_type = $item->board_type;

        if (array_key_exists('label', $validated)) {
            $item->slug = $this->uniqueSlug($workspace, $item->parent_id, $validated['label'], $item->id);
        }

        $item->fill($validated)->save();

        if (array_key_exists('label', $validated) && $validated['label'] !== $previous_label) {
            $this->activity_logger->log(
                $item,
                $request->user(),
                BoardActivityLog::ACTION_RENAMED,
                "Renamed the board from \"{$previous_label}\" to \"{$validated['label']}\"",
                ['from' => $previous_label, 'to' => $validated['label']]
            );
        }

        if (array_key_exists('board_type', $validated) && $validated['board_type'] !== $previous_board_type) {
            $this->activity_logger->log(
                $item,
                $request->user(),
                BoardActivityLog::ACTION_TYPE_CHANGED,
                "Changed the board type from \"{$previous_board_type}\" to \"{$validated['board_type']}\"",
                ['from' => $previous_board_type, 'to' => $validated['board_type']]
            );
        }

        return response()->json([
            'message' => 'Navigation item updated successfully.',
            'item' => new WorkspaceNavigationItemResource($item->fresh()->load(['creator', 'workspace.owners'])),
        ]);
    }

    /**
     * PATCH /api/workspaces/{workspace}/navigation/{item}/move
     */
    public function move(
        MoveWorkspaceNavigationItemRequest $request,
        Workspace $workspace,
        WorkspaceNavigationItem $item
    ): JsonResponse {
        $this->ensureItemBelongsToWorkspace($workspace, $item);

        $validated = $request->validated();
        $new_parent_id = array_key_exists('parent_id', $validated) ? $validated['parent_id'] : $item->parent_id;

        if ($new_parent_id !== null) {
            if ((int) $new_parent_id === $item->id || $this->isDescendant($item, (int) $new_parent_id)) {
                return response()->json([
                    'message' => 'A navigation item cannot be moved inside itself or one of its descendants.',
                ], 422);
            }
        }

        $item->parent_id = $new_parent_id;
        $item->position = $validated['position'] ?? $this->nextPosition($workspace, $new_parent_id);
        $item->save();

        return response()->json([
            'message' => 'Navigation item moved successfully.',
            'item' => new WorkspaceNavigationItemResource($item->fresh()),
        ]);
    }

    /**
     * PATCH /api/workspaces/{workspace}/navigation/reorder
     *
     * Sidebar drag-and-drop reordering (and the "Move up"/"Move down" quick
     * actions, which just submit a two-item swap) — the full new sibling
     * order for `target_parent_id` is resequenced server-side in one
     * transaction, mirroring {@see \App\Http\Controllers\Board\BoardItemController::reorder()}.
     * `source_ordered_ids`/`source_parent_id` are only sent when the dragged
     * item changed folders, so the old parent's remaining children stay
     * contiguously ordered too.
     */
    public function reorder(ReorderWorkspaceNavigationItemsRequest $request, Workspace $workspace): JsonResponse
    {
        $validated = $request->validated();
        $moved_item = $workspace->navigationItems()->findOrFail($validated['moved_item_id']);
        $target_parent_id = array_key_exists('target_parent_id', $validated) ? $validated['target_parent_id'] : $moved_item->parent_id;

        if ($target_parent_id !== null) {
            if ((int) $target_parent_id === $moved_item->id || $this->isDescendant($moved_item, (int) $target_parent_id)) {
                return response()->json([
                    'message' => 'A navigation item cannot be moved inside itself or one of its descendants.',
                ], 422);
            }
        }

        $touched_ids = [];

        DB::transaction(function () use ($workspace, $moved_item, $target_parent_id, $validated, &$touched_ids) {
            if ($target_parent_id !== $moved_item->parent_id) {
                $moved_item->parent_id = $target_parent_id;
                $moved_item->save();
            }

            foreach ($validated['target_ordered_ids'] as $position => $id) {
                WorkspaceNavigationItem::where('id', $id)
                    ->where('workspace_id', $workspace->id)
                    ->where('parent_id', $target_parent_id)
                    ->update(['position' => $position]);
            }
            $touched_ids = $validated['target_ordered_ids'];

            if (! empty($validated['source_ordered_ids'])) {
                $source_parent_id = $validated['source_parent_id'] ?? null;
                foreach ($validated['source_ordered_ids'] as $position => $id) {
                    WorkspaceNavigationItem::where('id', $id)
                        ->where('workspace_id', $workspace->id)
                        ->where('parent_id', $source_parent_id)
                        ->update(['position' => $position]);
                }
                $touched_ids = [...$touched_ids, ...$validated['source_ordered_ids']];
            }
        });

        $items = WorkspaceNavigationItem::whereIn('id', $touched_ids)->get();

        return response()->json([
            'message' => 'Navigation items reordered successfully.',
            'items' => WorkspaceNavigationItemResource::collection($items),
        ]);
    }

    /**
     * POST /api/workspaces/{workspace}/navigation/{item}/duplicate
     *
     * Deep-copies the nav-tree subtree itself (see `copySubtree()`) and,
     * for every leaf in that subtree (a real board, not just a folder), also
     * deep-copies its board content — every tab's columns, groups, items and
     * cell values — via {@see BoardDuplicationService}, so "Duplicate board"
     * from the board options menu produces a genuinely independent copy
     * rather than an empty shell.
     */
    public function duplicate(Request $request, Workspace $workspace, WorkspaceNavigationItem $item): JsonResponse
    {
        $this->ensureItemBelongsToWorkspace($workspace, $item);

        $item->load('childrenRecursive');

        $copy = $this->copySubtree($item, $item->parent_id, $this->nextPosition($workspace, $item->parent_id), $request->user()?->id);
        $copy->load(['childrenRecursive', 'creator', 'workspace.owners']);

        $this->activity_logger->log(
            $copy,
            $request->user(),
            BoardActivityLog::ACTION_DUPLICATED,
            "Duplicated from \"{$item->label}\""
        );

        return response()->json([
            'message' => 'Navigation item duplicated successfully.',
            'item' => new WorkspaceNavigationItemResource($copy),
        ], 201);
    }

    /**
     * DELETE /api/workspaces/{workspace}/navigation/{item}
     *
     * Soft-deletes (archives) the item; the cascade removes descendants.
     */
    public function destroy(Request $request, Workspace $workspace, WorkspaceNavigationItem $item): JsonResponse
    {
        $this->ensureItemBelongsToWorkspace($workspace, $item);

        $this->activity_logger->log($item, $request->user(), BoardActivityLog::ACTION_DELETED, 'Deleted the board');
        $this->deleteSubtree($item);

        return response()->json([
            'message' => 'Navigation item deleted successfully.',
        ]);
    }

    /**
     * Guard: abort with 404 when the item is not part of the workspace.
     */
    private function ensureItemBelongsToWorkspace(Workspace $workspace, WorkspaceNavigationItem $item): void
    {
        abort_if($item->workspace_id !== $workspace->id, 404);
    }

    /**
     * The next free position among a parent's children (append to the end).
     */
    private function nextPosition(Workspace $workspace, ?int $parent_id): int
    {
        return (int) $workspace->navigationItems()
            ->where('parent_id', $parent_id)
            ->max('position') + 1;
    }

    /**
     * Build a slug that is unique among its siblings.
     */
    private function uniqueSlug(Workspace $workspace, ?int $parent_id, string $label, ?int $exclude_id = null): string
    {
        $base = Str::slug($label) ?: 'item';
        $slug = $base;
        $suffix = 1;

        $exists = function (string $candidate) use ($workspace, $parent_id, $exclude_id): bool {
            return $workspace->navigationItems()
                ->where('parent_id', $parent_id)
                ->where('slug', $candidate)
                ->when($exclude_id !== null, fn ($query) => $query->where('id', '!=', $exclude_id))
                ->exists();
        };

        while ($exists($slug)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Whether $candidate_id is a descendant of $item (used to prevent cycles).
     */
    private function isDescendant(WorkspaceNavigationItem $item, int $candidate_id): bool
    {
        $item->loadMissing('childrenRecursive');

        foreach ($this->flattenTree($item->childrenRecursive) as $descendant) {
            if ($descendant->id === $candidate_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively deep-copy an item and its subtree under a new parent.
     */
    private function copySubtree(WorkspaceNavigationItem $item, ?int $parent_id, int $position, ?int $created_by_id): WorkspaceNavigationItem
    {
        $copy = $item->workspace->navigationItems()->create([
            'parent_id' => $parent_id,
            'type' => $item->type,
            'label' => $parent_id === $item->parent_id ? $item->label.' (copy)' : $item->label,
            'description' => $item->description,
            'slug' => $this->uniqueSlug($item->workspace, $parent_id, $item->label),
            'icon' => $item->icon,
            'view_key' => $item->view_key,
            'href' => $item->href,
            'display_style' => $item->display_style,
            'board_type' => $item->board_type,
            'is_favorite' => false,
            'is_priority' => false,
            'position' => $position,
            'created_by_id' => $created_by_id,
        ]);

        if ($item->type === WorkspaceNavigationItem::TYPE_LEAF) {
            $this->duplication_service->duplicateAllViews($item, $copy, $created_by_id);
        }

        foreach ($item->childrenRecursive as $child) {
            $this->copySubtree($child, $copy->id, $child->position, $created_by_id);
        }

        return $copy;
    }

    /**
     * Soft-delete an item and every descendant.
     */
    private function deleteSubtree(WorkspaceNavigationItem $item): void
    {
        $item->loadMissing('childrenRecursive');

        foreach ($this->flattenTree($item->childrenRecursive) as $descendant) {
            $descendant->delete();
        }

        $item->delete();
    }

    /**
     * Flatten a nested collection of items into a single list.
     *
     * @param  Collection<int, WorkspaceNavigationItem>|iterable<WorkspaceNavigationItem>  $items
     * @return array<int, WorkspaceNavigationItem>
     */
    private function flattenTree(iterable $items): array
    {
        $flat = [];

        foreach ($items as $item) {
            $flat[] = $item;
            $flat = array_merge($flat, $this->flattenTree($item->childrenRecursive));
        }

        return $flat;
    }
}
