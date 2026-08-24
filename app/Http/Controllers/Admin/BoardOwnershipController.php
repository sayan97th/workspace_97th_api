<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BoardOwnership\AssignOrphanBoardOwnerRequest;
use App\Http\Requests\Admin\BoardOwnership\BulkReassignBoardOwnerRequest;
use App\Http\Resources\AdminBoardResource;
use App\Models\WorkspaceNavigationItem;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BoardOwnershipController extends Controller
{
    /**
     * GET /api/admin/board-ownership/orphans
     *
     * Boards (the `WorkspaceNavigationItem::scopeBoards()` bucket — excludes docs,
     * dashboards, workflows, and the workspace-manage view) that have never been assigned
     * an owner.
     */
    public function orphans(): JsonResponse
    {
        $boards = WorkspaceNavigationItem::query()
            ->boards()
            ->whereNull('owner_id')
            ->orderBy('label')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => AdminBoardResource::collection($boards),
        ]);
    }

    /**
     * POST /api/admin/board-ownership/reassign
     *
     * Bulk-moves every board currently owned by `current_owner_id` to `new_owner_id`. High
     * blast radius (can silently move ownership of every board a person owns) — the
     * frontend gates this behind a confirmation modal, and this is the single most
     * consequential write in the whole Administration surface.
     */
    public function bulkReassign(BulkReassignBoardOwnerRequest $request): JsonResponse
    {
        $current_owner_id = (int) $request->validated('current_owner_id');
        $new_owner_id = (int) $request->validated('new_owner_id');

        $reassigned_count = DB::transaction(function () use ($current_owner_id, $new_owner_id) {
            return WorkspaceNavigationItem::query()
                ->boards()
                ->where('owner_id', $current_owner_id)
                ->update(['owner_id' => $new_owner_id]);
        });

        AuditLogger::log(
            'board_ownership.reassigned',
            "Reassigned {$reassigned_count} board(s) from user #{$current_owner_id} to user #{$new_owner_id}.",
            $request->user(),
            ['current_owner_id' => $current_owner_id, 'new_owner_id' => $new_owner_id, 'reassigned_count' => $reassigned_count]
        );

        return response()->json([
            'message' => $reassigned_count === 1
                ? '1 board reassigned successfully.'
                : "{$reassigned_count} boards reassigned successfully.",
            'reassigned_count' => $reassigned_count,
        ]);
    }

    /**
     * PATCH /api/admin/board-ownership/orphans/{item}
     */
    public function assignOrphan(AssignOrphanBoardOwnerRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        abort_if($item->type !== WorkspaceNavigationItem::TYPE_LEAF, 404);

        $item->update(['owner_id' => $request->validated('owner_id')]);
        $item->load('owner');

        AuditLogger::log(
            'board_ownership.orphan_assigned',
            "Assigned an owner to board \"{$item->label}\".",
            $request->user(),
            ['board_id' => $item->id, 'owner_id' => $item->owner_id]
        );

        return response()->json([
            'message' => 'Board owner assigned successfully.',
            'board' => new AdminBoardResource($item),
        ]);
    }
}
