<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
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
 * @property string $slug
 * @property string|null $icon
 * @property string|null $view_key
 * @property string|null $href
 * @property string|null $display_style
 * @property bool $is_favorite
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Workspace $workspace
 * @property-read WorkspaceNavigationItem|null $parent
 * @property-read Collection<int, WorkspaceNavigationItem> $children
 * @property-read Collection<int, WorkspaceNavigationItem> $childrenRecursive
 */
#[Fillable([
    'workspace_id',
    'parent_id',
    'type',
    'label',
    'slug',
    'icon',
    'view_key',
    'href',
    'display_style',
    'is_favorite',
    'position',
])]
class WorkspaceNavigationItem extends Model
{
    use SoftDeletes;

    public const TYPE_GROUP = 'group';

    public const TYPE_LEAF = 'leaf';

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
        return $this->children()->with('childrenRecursive');
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
