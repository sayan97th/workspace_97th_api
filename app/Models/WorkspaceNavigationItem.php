<?php

namespace App\Models;

use App\Concerns\HasRandomBigId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property bool $is_favorite
 * @property int $position
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Workspace $workspace
 * @property-read WorkspaceNavigationItem|null $parent
 * @property-read Collection<int, WorkspaceNavigationItem> $children
 * @property-read Collection<int, WorkspaceNavigationItem> $childrenRecursive
 * @property-read User|null $creator
 * @property-read Collection<int, BoardColumn> $columns
 * @property-read Collection<int, BoardGroup> $groups
 * @property-read Collection<int, BoardItem> $items
 * @property-read Collection<int, BoardView> $views
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
    'is_favorite',
    'position',
    'created_by_id',
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
            'position' => 'integer',
        ];
    }
}
