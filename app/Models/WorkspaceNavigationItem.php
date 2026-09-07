<?php

namespace App\Models;

use App\Concerns\HasRandomBigId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single node in a workspace's navigation tree.
 *
 * The tree is self-referencing: a node with `type = group` behaves like a folder
 * that can contain other groups and leaves, while `type = leaf` is a navigable
 * view. Nesting is unbounded.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $parent_id
 * @property string $type
 * @property string $label
 * @property string|null $description
 * @property string $slug
 * @property string|null $icon
 * @property string|null $view_key
 * @property string|null $href
 * @property string|null $display_style
 * @property string $board_type
 * @property string|null $item_column_label
 * @property int|null $item_column_width
 * @property int|null $sub_item_column_width
 * @property bool $is_favorite
 * @property bool $is_archived
 * @property Carbon|null $archived_at
 * @property int $position
 * @property int|null $created_by_id
 * @property int|null $owner_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Workspace $workspace
 * @property-read WorkspaceNavigationItem|null $parent
 * @property-read Collection<int, WorkspaceNavigationItem> $children
 * @property-read Collection<int, WorkspaceNavigationItem> $childrenRecursive
 * @property-read User|null $creator
 * @property-read User|null $owner
 * @property-read Collection<int, BoardColumn> $columns
 * @property-read Collection<int, BoardGroup> $groups
 * @property-read Collection<int, BoardItem> $items
 * @property-read Collection<int, BoardView> $views
 * @property-read Collection<int, BoardComment> $comments
 * @property-read Collection<int, BoardDiscussionView> $discussionViews
 */
#[Fillable([
    'workspace_id',
    'parent_id',
    'type',
    'label',
    'description',
    'slug',
    'icon',
    'view_key',
    'href',
    'display_style',
    'board_type',
    'item_column_label',
    'item_column_width',
    'sub_item_column_width',
    'is_favorite',
    'is_archived',
    'archived_at',
    'position',
    'created_by_id',
    'owner_id',
])]
class WorkspaceNavigationItem extends Model
{
    use HasFactory, HasRandomBigId, SoftDeletes;

    /** The id is a randomly-generated 10-digit number, not an auto-increment. */
    public $incrementing = false;

    protected $keyType = 'int';

    public const TYPE_GROUP = 'group';

    public const TYPE_LEAF = 'leaf';

    /** Visible to every workspace member — the default for a new board. */
    public const BOARD_TYPE_MAIN = 'main';

    /** Only visible to people explicitly added to the board. */
    public const BOARD_TYPE_PRIVATE = 'private';

    /** Visible to workspace members and can also be shared with people outside it. */
    public const BOARD_TYPE_SHAREABLE = 'shareable';

    public const ASSET_TYPE_BOARD = 'board';

    public const ASSET_TYPE_DOC = 'doc';

    public const ASSET_TYPE_DASHBOARD = 'dashboard';

    public const ASSET_TYPE_WORKFLOW = 'workflow';

    /**
     * `view_key`s that map to each of the Content tab's "Asset type" facets. Any
     * `view_key` not listed here (including `board`/`project`/`portfolio`/null)
     * falls back to {@see ASSET_TYPE_BOARD} — see {@see assetType()}.
     *
     * @var array<string, array<int, string>>
     */
    public const ASSET_TYPE_VIEW_KEYS = [
        self::ASSET_TYPE_DOC => ['doc'],
        self::ASSET_TYPE_DASHBOARD => ['dashboard'],
        self::ASSET_TYPE_WORKFLOW => ['workflow'],
    ];

    /**
     * The workspace this item belongs to.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The parent node, or null when this item is a root.
     *
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Direct children, ordered for display.
     *
     * @return HasMany<WorkspaceNavigationItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * Children eager-loaded recursively so the whole subtree loads in one pass.
     *
     * @return HasMany<WorkspaceNavigationItem, $this>
     */
    public function childrenRecursive(): HasMany
    {
        return $this->children()->with(['childrenRecursive', 'creator', 'workspace.owners']);
    }

    /**
     * The user who created this item, shown as "Created by" in the info popover.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The user administratively responsible for this board, set/reassigned from
     * Administration > Board ownership — distinct from {@see creator()}, which never
     * changes once the board is made.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Every column across every tab of this board. Per-tab content now flows
     * through {@link BoardView::columns()} — use that when you need just one
     * tab's columns.
     *
     * @return HasMany<BoardColumn, $this>
     */
    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class, 'board_id')->orderBy('position');
    }

    /**
     * Every group ("table") across every tab of this board. Per-tab content
     * now flows through {@link BoardView::groups()} — use that when you need
     * just one tab's groups.
     *
     * @return HasMany<BoardGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(BoardGroup::class, 'board_id')->orderBy('position');
    }

    /**
     * Every item ("pulse") on this board, across all groups.
     *
     * @return HasMany<BoardItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BoardItem::class, 'board_id');
    }

    /**
     * This board's saved views ("tabs"), ordered for display.
     *
     * @return HasMany<BoardView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(BoardView::class, 'board_id')->orderBy('position');
    }

    /**
     * Top-level comments ("updates") on this board's discussion feed, across
     * every view/tab — the board-wide analogue of {@link BoardItem::comments()}.
     *
     * @return HasMany<BoardComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(BoardComment::class, 'board_id');
    }

    /**
     * One row per user who has ever opened this board's discussion drawer,
     * tracking when they last saw it. See {@link BoardDiscussionView}.
     *
     * @return HasMany<BoardDiscussionView, $this>
     */
    public function discussionViews(): HasMany
    {
        return $this->hasMany(BoardDiscussionView::class, 'board_id');
    }

    /**
     * Email invitations granting view access to this specific board (pending,
     * expired or accepted) — independent of the invitee's workspace
     * membership. See {@link BoardInvitation}.
     *
     * @return HasMany<BoardInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(BoardInvitation::class, 'board_id');
    }

    /**
     * This board's activity log entries (rename, archive, duplicate, delete,
     * ...), newest first — see {@link BoardActivityLog}.
     *
     * @return HasMany<BoardActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(BoardActivityLog::class, 'board_id')->latest('created_at');
    }

    /**
     * People who accepted a board invitation and were granted explicit
     * view access to this board, on top of whoever already sees it through
     * their workspace role.
     *
     * @return BelongsToMany<User, $this>
     */
    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'board_collaborators', 'board_id', 'user_id')
            ->withPivot('invited_by')
            ->withTimestamps();
    }

    /**
     * Scope: leaves that count as an actual "board" (not a doc/dashboard/workflow, and not
     * the special workspace-manage view), matching {@see ContentController}'s own asset-type
     * bucketing. Used by Administration > Board ownership, which only deals in real boards.
     *
     * @param  Builder<WorkspaceNavigationItem>  $query
     * @return Builder<WorkspaceNavigationItem>
     */
    public function scopeBoards(Builder $query): Builder
    {
        $non_board_view_keys = collect(self::ASSET_TYPE_VIEW_KEYS)->flatten()->push('workspace_manage');

        return $query->where('type', self::TYPE_LEAF)
            ->where(fn (Builder $q) => $q->whereNotIn('view_key', $non_board_view_keys)->orWhereNull('view_key'));
    }

    /**
     * Scope: hides archived boards from the normal navigation tree/listing
     * queries — an archived board still exists (and is reachable from the
     * "View archive / trash" panel) but shouldn't clutter the sidebar or the
     * workspace's Content tab.
     *
     * @param  Builder<WorkspaceNavigationItem>  $query
     * @return Builder<WorkspaceNavigationItem>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * Coarse asset-type bucket for the Content tab's "Asset type" filter —
     * board/doc/dashboard/workflow, derived from `view_key`.
     */
    public function assetType(): string
    {
        foreach (self::ASSET_TYPE_VIEW_KEYS as $type => $view_keys) {
            if (in_array($this->view_key, $view_keys, true)) {
                return $type;
            }
        }

        return self::ASSET_TYPE_BOARD;
    }

    /**
     * Every ancestor from the workspace root down to (not including) this item,
     * walking the adjacency list one `parent_id` hop at a time. There's no
     * nested-set or materialized-path column to short-circuit this, but nesting
     * is shallow in practice so the extra queries are cheap.
     *
     * @return Collection<int, WorkspaceNavigationItem>
     */
    public function ancestors(): Collection
    {
        $trail = [];
        $node = $this->parent;

        while ($node !== null) {
            $trail[] = $node;
            $node = $node->parent;
        }

        return new Collection(array_reverse($trail));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_favorite' => 'boolean',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
            'position' => 'integer',
            'item_column_width' => 'integer',
            'sub_item_column_width' => 'integer',
        ];
    }
}
