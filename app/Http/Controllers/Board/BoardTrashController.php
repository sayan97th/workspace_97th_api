<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Models\BoardActivityLog;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardActivityLogger;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The board options menu's "View archive / trash" panel — this single
 * board's own archived items (hidden via the selection action bar's
 * "Archive", {@see BoardItemController::bulkArchive()}) and deleted items
 * (soft-deleted via "Delete"/`destroy()`), with restore / delete-forever
 * actions. Also lists the board's archived groups (tables), archived from the
 * group menu's "Archive group" ({@see BoardGroupController::archive()}). Scoped to one board, unlike the account-wide Trash dialog opened
 * from the top bar.
 */
class BoardTrashController extends Controller
{
    public function __construct(private readonly BoardActivityLogger $activity_logger) {}

    /**
     * GET /api/boards/{item}/trash
     */
    public function index(WorkspaceNavigationItem $item): JsonResponse
    {
        $archived = $item->items()
            ->where('is_archived', true)
            ->with(['group', 'creator'])
            ->orderByDesc('updated_at')
            ->get();

        $trashed = $item->items()
            ->onlyTrashed()
            ->with(['group', 'creator'])
            ->orderByDesc('deleted_at')
            ->get();

        $archived_groups = BoardGroup::where('board_id', $item->id)
            ->where('is_archived', true)
            ->withCount(['items as item_count' => fn ($q) => $q->whereNull('parent_id')->where('is_archived', false)])
            ->orderByDesc('archived_at')
            ->get();

        return response()->json([
            'archived' => $this->presentEntries($archived, 'archived_at'),
            'trashed' => $this->presentEntries($trashed, 'deleted_at'),
            'archived_groups' => $archived_groups->map(fn (BoardGroup $group) => [
                'id' => (string) $group->id,
                'name' => $group->name,
                'accent_color' => $group->accent_color,
                'item_count' => $group->item_count,
                'timestamp' => $group->archived_at ?? $group->updated_at,
            ])->values()->all(),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/trash/groups/{group}/restore
     *
     * Un-archives a group, it comes back at the end of its tab's group order
     * (its old slot may since have been taken by another table).
     */
    public function restoreGroup(Request $request, WorkspaceNavigationItem $item, int $group): JsonResponse
    {
        BoardEditGate::authorize($item, $request->user());

        $target = BoardGroup::where('board_id', $item->id)->where('is_archived', true)->findOrFail($group);

        $target->update([
            'is_archived' => false,
            'archived_at' => null,
            'position' => (int) BoardGroup::where('board_view_id', $target->board_view_id)->where('is_archived', false)->max('position') + 1,
        ]);

        return response()->json(['message' => 'Table restored successfully.']);
    }

    /**
     * DELETE /api/boards/{item}/trash/groups/{group}
     *
     * Permanently deletes an archived group and, through the database
     * cascade, every item it holds.
     */
    public function forceDeleteGroup(Request $request, WorkspaceNavigationItem $item, int $group): JsonResponse
    {
        BoardEditGate::authorize($item, $request->user());

        BoardGroup::where('board_id', $item->id)->where('is_archived', true)->findOrFail($group)->delete();

        return response()->json(['message' => 'Table permanently deleted.']);
    }

    /**
     * PATCH /api/boards/{item}/trash/{board_item}/restore
     *
     * Un-archives an archived item, or un-deletes a soft-deleted one —
     * whichever state `$board_item` is actually in.
     */
    public function restore(Request $request, WorkspaceNavigationItem $item, int $board_item): JsonResponse
    {
        $target = BoardItem::withTrashed()->where('board_id', $item->id)->findOrFail($board_item);

        if ($target->trashed()) {
            $target->restore();
        }
        if ($target->is_archived) {
            $target->update(['is_archived' => false]);
        }

        $this->activity_logger->log(
            $item,
            $request->user(),
            BoardActivityLog::ACTION_ITEM_RESTORED,
            "Restored \"{$target->name}\"",
            ['item_id' => $target->id]
        );

        return response()->json(['message' => 'Item restored successfully.']);
    }

    /**
     * DELETE /api/boards/{item}/trash/{board_item}
     *
     * Permanently deletes an archived or already-soft-deleted item — there's
     * no further undo past this point, unlike the regular `destroy()`.
     */
    public function forceDelete(Request $request, WorkspaceNavigationItem $item, int $board_item): JsonResponse
    {
        $target = BoardItem::withTrashed()->where('board_id', $item->id)->findOrFail($board_item);
        $name = $target->name;
        $target_id = $target->id;
        $target->forceDelete();

        $this->activity_logger->log(
            $item,
            $request->user(),
            BoardActivityLog::ACTION_ITEM_DELETED,
            "Permanently deleted \"{$name}\"",
            ['item_id' => $target_id]
        );

        return response()->json(['message' => 'Item permanently deleted.']);
    }

    /**
     * @param  Collection<int, BoardItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function presentEntries($items, string $timestamp_field): array
    {
        return $items->map(fn (BoardItem $board_item) => [
            'id' => (string) $board_item->id,
            'name' => $board_item->name,
            'group_name' => $board_item->group?->name ?? '—',
            'timestamp' => $board_item->{$timestamp_field},
            // Not necessarily who archived/deleted it (that actor isn't
            // tracked) — the item's original creator, shown as a fallback
            // "by" attribution the same way the board info popover does.
            'created_by' => $board_item->creator ? [
                'id' => $board_item->creator->id,
                'full_name' => $board_item->creator->full_name,
                'profile_photo_url' => $board_item->creator->profile_photo_url,
            ] : null,
        ])->values()->all();
    }
}
