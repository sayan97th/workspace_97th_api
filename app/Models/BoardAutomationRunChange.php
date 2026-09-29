<?php

namespace App\Models;

use App\Services\Board\BoardAutomationRunUndoer;
use App\Services\Board\BoardAutomationService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One change an automation run made inside the app, recorded while it ran so the whole run can be
 * undone from the run history, see {@see BoardAutomationRunUndoer}. Every change of one run shares
 * the `run_uuid` of its run history rows ({@see BoardAutomationService}).
 *
 * - {@see self::KIND_VALUE}: `column_id` went from `before` to `after` on `board_item_id`.
 * - {@see self::KIND_MOVED}: the item moved from the group `before.group_id` to `after.group_id`.
 * - {@see self::KIND_ARCHIVED}: the item was archived.
 * - {@see self::KIND_DELETED}: the item (and the subitems listed in `after.descendant_ids`) was deleted.
 * - {@see self::KIND_RENAMED}: the item's name went from `before.name` to `after.name`.
 * - {@see self::KIND_CREATED}: the run created the item.
 *
 * Changes of automations a run set off belong to those automations' own runs.
 *
 * @property int $id
 * @property string $run_uuid
 * @property int|null $automation_id
 * @property int $board_id
 * @property int|null $board_item_id
 * @property string $kind
 * @property int|null $column_id
 * @property mixed $before
 * @property mixed $after
 * @property Carbon|null $undone_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['run_uuid', 'automation_id', 'board_id', 'board_item_id', 'kind', 'column_id', 'before', 'after', 'undone_at'])]
class BoardAutomationRunChange extends Model
{
    public const KIND_VALUE = 'value';

    public const KIND_MOVED = 'moved';

    public const KIND_ARCHIVED = 'archived';

    public const KIND_DELETED = 'deleted';

    public const KIND_RENAMED = 'renamed';

    public const KIND_CREATED = 'created';

    /** Items put in a new order, `before.order` and `after.order` list their ids. */
    public const KIND_REORDERED = 'reordered';

    /** How long after a run it can still be undone. */
    public const UNDO_WINDOW_DAYS = 30;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'undone_at' => 'datetime',
        ];
    }
}
