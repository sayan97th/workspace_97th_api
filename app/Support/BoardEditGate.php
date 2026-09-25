<?php

namespace App\Support;

use App\Enums\BoardEditPermission;
use App\Http\Controllers\Auth\BoardInvitationController;
use App\Http\Resources\BoardResource;
use App\Models\BoardCollaborator;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gates what a user may change on a board, resolved into one of four
 * levels:
 *
 * - `full`: content and structure (columns, groups, views).
 * - `content`: items and their values, but not the structure.
 * - `assigned`: only the items the user is assigned to in a People column.
 * - `none`: read only.
 *
 * Board owners (see {@see BoardManagementGate}, plus the Administration
 * "board owner" `owner_id`) and a user holding one of
 * {@see PRIVILEGED_GLOBAL_ROLES} always get `full`. A workspace `viewer`
 * (a board guest invited through
 * {@see BoardInvitationController} gets exactly
 * this role) always gets `none`. Everybody else gets the level the board's
 * `edit_permission` ({@see BoardEditPermission}) grants members.
 *
 * Deliberately does not grant access through {@see BoardCollaborator}
 * alone: a board-invited guest is added to `board_collaborators` *and* given
 * the workspace `viewer` role in the same transaction, so treating
 * "is a collaborator" as "can edit" would grant every invited guest full
 * write access, exactly the case this gate exists to prevent.
 */
class BoardEditGate
{
    public const LEVEL_FULL = 'full';

    public const LEVEL_CONTENT = 'content';

    public const LEVEL_ASSIGNED = 'assigned';

    public const LEVEL_NONE = 'none';

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
     * Whether `$user` counts as an owner of `$item`, so the board permission
     * mode never restricts them.
     */
    public static function isOwner(WorkspaceNavigationItem $item, User $user): bool
    {
        if ($item->owner_id !== null && $item->owner_id === $user->id) {
            return true;
        }

        return BoardManagementGate::allows($item, $user);
    }

    /**
     * The edit level `$user` has on `$item` (see the class doc).
     */
    public static function level(WorkspaceNavigationItem $item, User $user): string
    {
        if ($user->hasRole(self::PRIVILEGED_GLOBAL_ROLES) || $item->created_by_id === $user->id) {
            return self::LEVEL_FULL;
        }

        $membership_role = DB::table('workspace_user')
            ->where('workspace_id', $item->workspace_id)
            ->where('user_id', $user->id)
            ->value('role');

        if ($membership_role === 'owner' || ($item->owner_id !== null && $item->owner_id === $user->id)) {
            return self::LEVEL_FULL;
        }

        if ($membership_role === self::READ_ONLY_WORKSPACE_ROLE) {
            return self::LEVEL_NONE;
        }

        return match (self::permission($item)) {
            BoardEditPermission::Content => self::LEVEL_CONTENT,
            BoardEditPermission::AssignedItems => self::LEVEL_ASSIGNED,
            BoardEditPermission::ViewOnly => self::LEVEL_NONE,
            default => self::LEVEL_FULL,
        };
    }

    /**
     * The board's configured permission mode, `everything` when unset.
     */
    public static function permission(WorkspaceNavigationItem $item): BoardEditPermission
    {
        return BoardEditPermission::tryFrom((string) $item->edit_permission) ?? BoardEditPermission::Everything;
    }

    /**
     * Whether `$user` may edit anything at all on `$item`. Used by the
     * endpoints that are not tied to one item (comments, tags, trash) and
     * by `can_edit` on {@see BoardResource}.
     */
    public static function allows(WorkspaceNavigationItem $item, User $user): bool
    {
        return self::level($item, $user) !== self::LEVEL_NONE;
    }

    /**
     * Aborts with a 403 when `$user` has view only access to `$item`.
     */
    public static function authorize(WorkspaceNavigationItem $item, User $user): void
    {
        if (! self::allows($item, $user)) {
            self::deny('You have view-only access to this board.');
        }
    }

    /**
     * Whether `$user` may create, reorder and bulk edit items on `$item`.
     */
    public static function allowsContent(WorkspaceNavigationItem $item, User $user): bool
    {
        return in_array(self::level($item, $user), [self::LEVEL_FULL, self::LEVEL_CONTENT], true);
    }

    /**
     * Aborts with a 403 unless `$user` may edit every item on `$item`.
     */
    public static function authorizeContent(WorkspaceNavigationItem $item, User $user): void
    {
        $level = self::level($item, $user);

        if ($level === self::LEVEL_ASSIGNED) {
            self::deny('You can only edit the items you are assigned to on this board.');
        }

        if ($level === self::LEVEL_NONE) {
            self::deny('You have view-only access to this board.');
        }
    }

    /**
     * Whether `$user` may change `$item`'s structure (columns, groups, views).
     */
    public static function allowsStructure(WorkspaceNavigationItem $item, User $user): bool
    {
        return self::level($item, $user) === self::LEVEL_FULL;
    }

    /**
     * Aborts with a 403 unless `$user` may change `$item`'s structure.
     */
    public static function authorizeStructure(WorkspaceNavigationItem $item, User $user): void
    {
        if (! self::allowsStructure($item, $user)) {
            self::deny('Only board owners can change the structure of this board.');
        }
    }

    /**
     * Whether `$user` may edit the single item `$board_item` of `$item`.
     */
    public static function allowsItem(WorkspaceNavigationItem $item, User $user, BoardItem $board_item): bool
    {
        return match (self::level($item, $user)) {
            self::LEVEL_FULL, self::LEVEL_CONTENT => true,
            self::LEVEL_ASSIGNED => self::isAssigned($board_item, $user),
            default => false,
        };
    }

    /**
     * Aborts with a 403 unless `$user` may edit `$board_item`.
     */
    public static function authorizeItem(WorkspaceNavigationItem $item, User $user, BoardItem $board_item): void
    {
        if (self::allowsItem($item, $user, $board_item)) {
            return;
        }

        self::deny(self::level($item, $user) === self::LEVEL_ASSIGNED
            ? 'You can only edit the items you are assigned to on this board.'
            : 'You have view-only access to this board.');
    }

    /**
     * Whether `$user` is in any People column of `$board_item`, or of its
     * parent when it is a subitem (being assigned to an item also lets you
     * work on its subitems, like monday.com).
     */
    public static function isAssigned(BoardItem $board_item, User $user): bool
    {
        $item_ids = array_values(array_filter([$board_item->id, $board_item->parent_id]));

        $people_values = BoardItemValue::query()
            ->whereIn('item_id', $item_ids)
            ->whereHas('column', fn ($query) => $query->where('type', BoardColumn::TYPE_PEOPLE))
            ->pluck('value');

        foreach ($people_values as $value) {
            if (is_array($value) && in_array((string) $user->id, array_map('strval', $value), true)) {
                return true;
            }
        }

        return false;
    }

    private static function deny(string $message): never
    {
        throw ValidationException::withMessages(['board' => $message])->status(403);
    }
}
