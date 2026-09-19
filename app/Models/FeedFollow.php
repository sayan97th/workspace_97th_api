<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One board or item a user follows, which is what fills the Update Feed's
 * "Following" tab. Following a board covers the board's own discussion and
 * every item on it, following an item covers just that item's updates. Each
 * target can be followed once per user (unique `[user_id, target_type, target_id]`).
 *
 * @property int $id
 * @property int $user_id
 * @property string $target_type
 * @property int $target_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'target_type', 'target_id'])]
class FeedFollow extends Model
{
    use HasFactory;

    public const TYPE_BOARD = 'board';

    public const TYPE_ITEM = 'item';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<FeedFollow>  $query
     * @return Builder<FeedFollow>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('target_type', $type);
    }
}
