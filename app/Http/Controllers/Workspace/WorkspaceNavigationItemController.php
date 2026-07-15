<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\MoveWorkspaceNavigationItemRequest;
use App\Http\Requests\Workspace\StoreWorkspaceNavigationItemRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceNavigationItemRequest;
use App\Http\Resources\WorkspaceNavigationItemResource;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WorkspaceNavigationItemController extends Controller
{
    /**
     * GET /api/workspaces/{workspace}/navigation
     *
     * Returns the full navigation tree for the workspace in one payload.
     */
    public function index(Workspace $workspace): JsonResponse
    {
        $tree = $workspace->rootNavigationItems()
            ->with(['childrenRecursive', 'creator', 'workspace.owners'])
            ->get();

        return response()->json([
            'data' => WorkspaceNavigationItemResource::collection($tree),
        ]);
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
            'position' => $validated['position'] ?? $this->nextPosition($workspace, $parent_id),
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Navigation item created successfully.',
            'item' => new WorkspaceNavigationItemResource($item->load(['creator', 'workspace.owners'])),
        ], 201);
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

        if (array_key_exists('label', $validated)) {
            $item->slug = $this->uniqueSlug($workspace, $item->parent_id, $validated['label'], $item->id);
        }

        $item->fill($validated)->save();

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
     * POST /api/workspaces/{workspace}/navigation/{item}/duplicate
     */
    public function duplicate(Request $request, Workspace $workspace, WorkspaceNavigationItem $item): JsonResponse
    {
        $this->ensureItemBelongsToWorkspace($workspace, $item);

        $item->load('childrenRecursive');

        $copy = $this->copySubtree($item, $item->parent_id, $this->nextPosition($workspace, $item->parent_id), $request->user()?->id);
        $copy->load(['childrenRecursive', 'creator', 'workspace.owners']);

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
    public function destroy(Workspace $workspace, WorkspaceNavigationItem $item): JsonResponse
    {
        $this->ensureItemBelongsToWorkspace($workspace, $item);

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
            'position' => $position,
            'created_by_id' => $created_by_id,
        ]);

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
