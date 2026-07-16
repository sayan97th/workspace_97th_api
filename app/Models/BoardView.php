<?php

namespace App\Models;

use App\Concerns\HasRandomBigId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property int $position
 * @property bool $is_primary
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
 */
#[Fillable([
    'board_id',
    'label',
    'position',
    'is_primary',
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_primary' => 'boolean',
            'filter_state' => 'array',
            'sort_state' => 'array',
            'hidden_column_ids' => 'array',
            'pinned_column_ids' => 'array',
            'conditional_color_rules' => 'array',
        ];
    }
}
