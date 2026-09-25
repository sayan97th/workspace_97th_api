<?php

namespace App\Enums;

use App\Support\BoardManagementGate;

/**
 * monday.com's board permission modes, chosen by a board owner from the
 * board options menu ("Board permissions"). Owners (see
 * {@see BoardManagementGate}) are never restricted by it.
 */
enum BoardEditPermission: string
{
    /** Members can edit content and structure (columns, groups, views). */
    case Everything = 'everything';

    /** Members can edit items and their values, but not columns, groups or views. */
    case Content = 'content';

    /** Members can only edit the items they are assigned to in a People column. */
    case AssignedItems = 'assigned_items';

    /** Members can only view the board. */
    case ViewOnly = 'view_only';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission) => $permission->value, self::cases());
    }
}
