<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gates board-management actions (invite/remove people, rename, change type,
 * archive, delete, duplicate, ...): allowed for the board's own creator, the
 * board's workspace owner, or a user holding one of {@see PRIVILEGED_GLOBAL_ROLES}.
 * Shared by every controller that needs this same check instead of each
 * re-deriving it (previously duplicated in
 * {@see \App\Http\Controllers\Board\BoardInvitationController}).
 */
class BoardManagementGate
{
    /**
     * Global roles that can manage any board, regardless of their own
     * membership in its workspace.
     */
    private const PRIVILEGED_GLOBAL_ROLES = ['super_admin', 'admin'];

    /**
     * Whether `$user` may manage `$item` (see class doc for the rule).
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

        return ($membership->role ?? null) === 'owner';
    }

    /**
     * Aborts with a 403 validation error when `$user` may not manage `$item`.
     * `$action` fills in the human-readable reason, e.g. "archive this board".
     */
    public static function authorize(WorkspaceNavigationItem $item, User $user, string $action): void
    {
        if (self::allows($item, $user)) {
            return;
        }

        throw ValidationException::withMessages([
            'board' => "Only the board's creator, a workspace owner, or an administrator can {$action}.",
        ])->status(403);
    }
}
