<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user's personal "Reorder (for you only)" tab order for one board — a
 * viewer-scoped override of the shared `board_views.position`/`pinned`
 * ordering that only affects what that user sees, not the board's other
 * collaborators. See {@link \App\Http\Controllers\Board\BoardViewController::updatePersonalOrder()}.
 *
 * @property int $id
 * @property int $user_id
 * @property int $board_id
 * @property array<int, int> $view_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read WorkspaceNavigationItem $board
 */
#[Fillable(['user_id', 'board_id', 'view_order'])]
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
        ];
    }
}
