<?php

namespace App\Models;

use App\Concerns\BelongsToBoardView;
use App\Services\Board\MirrorColumnResolver;
use Database\Factories\BoardColumnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single column definition on one tab (`board_view_id`) of a board, e.g.
 * "Status" or "Assigned to". The column `type` drives how {@link BoardItemValue}
 * values are shaped and how the frontend renders/edits cells. Columns are
 * independent per tab — two tabs on the same board may define the same `key` —
 * and independent per {@link scope}: a root item's columns and a subitem's
 * columns are two separate sets (mirroring monday.com, where subitems live on
 * an implicit separate sub-board with their own columns), so the same `key`
 * may also be reused once per scope within a tab.
 *
 * @property int $id
 * @property int $board_id
 * @property int $board_view_id
 * @property string $key
 * @property string $label
 * @property string $type
 * @property string $scope
 * @property int $position
 * @property int $width
 * @property array<string, mixed>|null $config
 * @property bool $hideable
 * @property bool $pinnable
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardView $boardView
 * @property-read Collection<int, BoardItemValue> $values
 */
#[Fillable([
    'board_id',
    'board_view_id',
    'key',
    'label',
    'type',
    'scope',
    'position',
    'width',
    'config',
    'hideable',
    'pinnable',
])]
class BoardColumn extends Model
{
    /** @use HasFactory<BoardColumnFactory> */
    use BelongsToBoardView, HasFactory;

    /** A column shown on the board's own (root) items. */
    public const SCOPE_ITEM = 'item';

    /** A column shown on subitems — a separate set from the parent item's own columns. */
    public const SCOPE_SUBITEM = 'subitem';

    public const TYPE_TEXT = 'text';

    public const TYPE_STATUS = 'status';

    public const TYPE_PEOPLE = 'people';

    public const TYPE_DATE = 'date';

    public const TYPE_TAGS = 'tags';

    /** A multi-select chip picker with no search box — like `TYPE_TAGS`, but each option is picked from the column's own fixed list rather than freely typed, and its cell picker has no search/"create new tag" affordance. */
    public const TYPE_DROPDOWN = 'dropdown';

    public const TYPE_NUMBER = 'number';

    public const TYPE_CHECKBOX = 'checkbox';

    /** Stores a `{start, end}` date-range value (both `YYYY-MM-DD`) — what the Gantt view's bars are actually driven by, mirroring monday.com's own Timeline column. */
    public const TYPE_TIMELINE = 'timeline';

    /** Stores an array of predecessor item ids (Finish-to-Start only, mirroring the most common of monday.com's four dependency modes) — drives the Gantt view's arrows and auto-reschedule. */
    public const TYPE_DEPENDENCY = 'dependency';

    /** A single-select colored pill styled as an outline badge (vs. Status's filled pill) — e.g. Priority. */
    public const TYPE_LABEL = 'label';

    /** A manually-set 0-100 percent value — distinct from the board's built-in Progress column, which is always computed from subitem/checkbox completion. */
    public const TYPE_PROGRESS = 'progress';

    /** A multi-line text value, rendered as a textarea instead of a single-line input. */
    public const TYPE_LONG_TEXT = 'long_text';

    public const TYPE_PHONE = 'phone';

    public const TYPE_EMAIL = 'email';

    /** A 0-5 star rating, set by clicking a star directly in the cell. */
    public const TYPE_RATING = 'rating';

    /** A team "vote" button — the cell stores the array of user ids who voted, mirroring a People column's own array shape. */
    public const TYPE_VOTE = 'vote';

    /** A clickable URL with its own display text, stored as `{url, text}` — distinct from a plain Text column, which has no separate display label. */
    public const TYPE_LINK = 'link';

    /** One or more files attached directly to this cell (not the item as a whole — see `BoardItemAttachment` for that), stored as an array of `{id, file_name, url, mime_type, size_bytes}`. */
    public const TYPE_FILES = 'files';

    /** A start/stop timer, stored as `{seconds, running_since}` — `running_since` is the ISO timestamp the timer was last started, or null while stopped; the displayed duration is `seconds` plus elapsed time since `running_since` when running. */
    public const TYPE_TIME_TRACKING = 'time_tracking';

    /** A read-only sequential number assigned once, server-side, when the item is created — mirrors monday.com's "Item ID" column. Never editable from the cell. */
    public const TYPE_AUTO_NUMBER = 'auto_number';

    /** A read-only value computed from other columns on the same item, per `config.operation` (`sum`/`subtract`/`multiply`/`divide`/`concat`) applied to `config.source_column_ids`. Never stores a {@see BoardItemValue} of its own; recomputed on every read. */
    public const TYPE_FORMULA = 'formula';

    /** Links this item to one or more items on a *different* board (`config.linked_board_id`), stored as an array of that board's item ids, mirroring `TYPE_DEPENDENCY`'s value shape but cross-board. The prerequisite a `TYPE_MIRROR` column reads through. */
    public const TYPE_CONNECT_BOARD = 'connect_board';

    /** A read-only value mirrored from the item(s) a `TYPE_CONNECT_BOARD` column (`config.source_column_id`) links to, reading `config.mirrored_column_id` off the linked board. Never stores a {@see BoardItemValue} of its own; resolved by {@see MirrorColumnResolver}. */
    public const TYPE_MIRROR = 'mirror';

    /** A per-item checklist of sub-tasks, stored as an array of `{id, text, is_done}` — distinct from {@see \App\Models\BoardItemChecklistItem}, which is one fixed checklist per item shown in its drawer, not a column an item can have several of. */
    public const TYPE_CHECKLIST = 'checklist';

    /**
     * Column types whose value is computed rather than written by a user, so no edit can ever
     * "change" them and they are not offered to a `column_changed` automation.
     *
     * @var array<int, string>
     */
    public const READ_ONLY_TYPES = [self::TYPE_FORMULA, self::TYPE_MIRROR, self::TYPE_AUTO_NUMBER];

    /**
     * The board (navigation leaf) this column belongs to.
     *
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * The tab (view) this column belongs to.
     *
     * @return BelongsTo<BoardView, $this>
     */
    public function boardView(): BelongsTo
    {
        return $this->belongsTo(BoardView::class, 'board_view_id');
    }

    /**
     * Every item's value stored against this column.
     *
     * @return HasMany<BoardItemValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(BoardItemValue::class, 'column_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'position' => 'integer',
            'width' => 'integer',
            'hideable' => 'boolean',
            'pinnable' => 'boolean',
        ];
    }
}
