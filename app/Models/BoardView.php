<?php

namespace App\Models;

use App\Concerns\HasRandomBigId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A saved tab on a board ("Main table", "By owner", …). A view row *is* both
 * the tab shown in {@link \App\Http\Controllers\Board\BoardViewController}
 * and the saved filter/sort/display configuration for that tab — updating a
 * view's `filter_state`/`sort_state`/etc is the "save filters for this board
 * view" action. Its id is a random 10-digit number (see {@link HasRandomBigId}),
 * matching `/boards/{board_id}/views/{id}` deep links.
 *
 * @property int $id
 * @property int $board_id
 * @property string $label
 * @property string $view_type
 * @property string|null $doc_content
 * @property array<string, mixed>|null $chart_config
 * @property string|null $emoji
 * @property int $position
 * @property bool $is_primary
 * @property bool $pinned
 * @property bool $is_locked
 * @property int|null $locked_by_id
 * @property array<string, mixed>|null $filter_state
 * @property array<int, mixed>|null $sort_state
 * @property string|null $group_by_option_id
 * @property array<int, string>|null $hidden_column_ids
 * @property array<int, string>|null $pinned_column_ids
 * @property string $row_height
 * @property array<int, mixed>|null $conditional_color_rules
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read User|null $creator
 * @property-read User|null $lockedBy
 * @property-read Collection<int, BoardColumn> $columns
 * @property-read Collection<int, BoardGroup> $groups
 */
#[Fillable([
    'board_id',
    'label',
    'view_type',
    'doc_content',
    'chart_config',
    'emoji',
    'position',
    'is_primary',
    'pinned',
    'is_locked',
    'locked_by_id',
    'filter_state',
    'sort_state',
    'group_by_option_id',
    'hidden_column_ids',
    'pinned_column_ids',
    'row_height',
    'conditional_color_rules',
    'created_by_id',
])]
class BoardView extends Model
{
    use HasFactory, HasRandomBigId;

    /** The id is a randomly-generated 10-digit number, not an auto-increment. */
    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * The board (navigation leaf) this view belongs to.
     *
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * The user who created this view.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The user who last locked this view, if it's currently locked.
     *
     * @return BelongsTo<User, $this>
     */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_id');
    }

    /**
     * This tab's own columns — independent from every other tab on the board.
     *
     * @return HasMany<BoardColumn, $this>
     */
    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class, 'board_view_id')->orderBy('position');
    }

    /**
     * This tab's own groups ("tables") — independent from every other tab on the board.
     *
     * @return HasMany<BoardGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(BoardGroup::class, 'board_view_id')->orderBy('position');
    }

    /**
     * This tab's own uploaded files — only meaningful for a `file_gallery`-type view.
     *
     * @return HasMany<BoardViewFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(BoardViewFile::class, 'board_view_id')->orderByDesc('created_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_primary' => 'boolean',
            'pinned' => 'boolean',
            'is_locked' => 'boolean',
            'filter_state' => 'array',
            'sort_state' => 'array',
            'hidden_column_ids' => 'array',
            'pinned_column_ids' => 'array',
            'conditional_color_rules' => 'array',
            'chart_config' => 'array',
        ];
    }
}
