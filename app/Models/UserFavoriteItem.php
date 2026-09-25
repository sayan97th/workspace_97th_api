<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A board (or folder) one user starred. Favorites are personal, so the same
 * board can be a favorite for one user and not for another.
 *
 * @property int $id
 * @property int $user_id
 * @property int $navigation_item_id
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read WorkspaceNavigationItem $navigationItem
 */
#[Fillable(['user_id', 'navigation_item_id', 'position'])]
class UserFavoriteItem extends Model
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
    public function navigationItem(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'navigation_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
