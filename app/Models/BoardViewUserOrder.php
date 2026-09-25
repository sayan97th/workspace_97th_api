<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user's personal tab preferences for one board: their own tab order (a
 * viewer-scoped override of the shared `board_views.position`/`pinned`
 * ordering), the tabs they hid for themselves and the tab they want opened
 * first. None of it affects the board's other collaborators. See
 * {@link \App\Http\Controllers\Board\BoardViewController::updatePersonalOrder()}
 * and {@link \App\Http\Controllers\Board\BoardViewController::updatePersonalPreferences()}.
 *
 * @property int $id
 * @property int $user_id
 * @property int $board_id
 * @property array<int, int>|null $view_order
 * @property array<int, int>|null $hidden_view_ids
 * @property int|null $default_view_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read WorkspaceNavigationItem $board
 */
#[Fillable(['user_id', 'board_id', 'view_order', 'hidden_view_ids', 'default_view_id'])]
class BoardViewUserOrder extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'view_order' => 'array',
            'hidden_view_ids' => 'array',
            'default_view_id' => 'integer',
        ];
    }
}
