<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A named combination of Update Feed filters a user saved, such as "Unread
 * from Ada this week". `filters` holds the same keys the feed's
 * `GET /api/feed/updates` accepts (tab, board_id, author_id, kind, q, from,
 * to, unread), private to `user_id`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property array<string, mixed> $filters
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'name', 'filters'])]
class FeedSavedView extends Model
{
    use HasFactory;

    /** Most views one user can keep. */
    public const MAX_PER_USER = 20;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
