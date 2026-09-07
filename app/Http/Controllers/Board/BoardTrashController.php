<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Models\BoardActivityLog;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The board options menu's "View archive / trash" panel — this single
 * board's own archived items (hidden via the selection action bar's
 * "Archive", {@see BoardItemController::bulkArchive()}) and deleted items
 * (soft-deleted via "Delete"/`destroy()`), with restore / delete-forever
 * actions. Scoped to one board, unlike the account-wide Trash dialog opened
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

        return response()->json([
            'archived' => $this->presentEntries($archived, 'archived_at'),
            'trashed' => $this->presentEntries($trashed, 'deleted_at'),
        ]);
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
            "Restored \"{$target->name}\""
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
        $target->forceDelete();

        $this->activity_logger->log(
            $item,
            $request->user(),
            BoardActivityLog::ACTION_ITEM_DELETED,
            "Permanently deleted \"{$name}\""
        );

        return response()->json(['message' => 'Item permanently deleted.']);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BoardItem>  $items
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
