<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gates whether a user may edit a board's content (cells, rows, groups,
 * columns), as opposed to just opening and browsing it. Permissive by
 * default (matching this app's existing authorization posture for board
 * items, which has no other per-board membership check today), denied only
 * for a workspace `viewer` — a board guest invited through
 * {@see \App\Http\Controllers\Auth\BoardInvitationController} gets exactly
 * this role. The board's own creator and a user holding one of
 * {@see PRIVILEGED_GLOBAL_ROLES} always pass, even if their workspace role
 * happens to be `viewer` too.
 *
 * Deliberately does not grant access through {@see \App\Models\BoardCollaborator}
 * alone: a board-invited guest is added to `board_collaborators` *and* given
 * the workspace `viewer` role in the same transaction, so treating
 * "is a collaborator" as "can edit" would grant every invited guest full
 * write access, exactly the case this gate exists to prevent. This mirrors
 * {@see \App\Support\WorkspacePermissionCatalog}'s existing "viewer is
 * read-only by design" intent, just enforced at request time (that catalog's
 * own grants aren't read anywhere yet).
 */
class BoardEditGate
{
    /**
     * Global roles that can edit any board, regardless of their own
     * membership in its workspace.
     */
    private const PRIVILEGED_GLOBAL_ROLES = ['super_admin', 'admin'];

    /**
     * The one workspace membership role denied edit access.
     */
    private const READ_ONLY_WORKSPACE_ROLE = 'viewer';

    /**
     * Whether `$user` may edit `$item`'s content.
     */
    public static function allows(WorkspaceNavigationItem $item, User $user): bool
    {
        if ($user->hasRole(self::PRIVILEGED_GLOBAL_ROLES)) {
            return true;
        }

        if ($item->created_by_id === $user->id) {
            return true;
        }

        $membership = DB::table('workspace_user')
            ->where('workspace_id', $item->workspace_id)
            ->where('user_id', $user->id)
            ->first();

        return ($membership->role ?? null) !== self::READ_ONLY_WORKSPACE_ROLE;
    }

    /**
     * Aborts with a 403 validation error when `$user` may not edit `$item`'s
     * content. Server-side backstop for the Table view's read-only mode
     * (`can_edit` on {@see \App\Http\Resources\BoardResource} just drives the
     * frontend's own cosmetic gating) — called from the item/column/group
     * mutation endpoints so a workspace `viewer` can't bypass the read-only
     * UI by calling the API directly.
     */
    public static function authorize(WorkspaceNavigationItem $item, User $user): void
    {
        if (self::allows($item, $user)) {
            return;
        }

        throw ValidationException::withMessages([
            'board' => 'You have view-only access to this board.',
        ])->status(403);
    }
}
