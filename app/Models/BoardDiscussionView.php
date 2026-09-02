<?php

namespace App\Models;

use App\Http\Controllers\Board\BoardCommentController;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user's "last viewed" mark on a board's whole discussion feed, upserted
 * every time {@see BoardCommentController::index()}
 * is called (i.e. every time that user opens `BoardDiscussionDrawer`). Powers
 * the "Board updates" badge's red/gray state on the frontend: red while the
 * board has comments newer than this row's `last_viewed_at` (or no row at
 * all), gray once caught up. Board-wide, unlike {@see BoardCommentView},
 * which marks a single comment as seen.
 *
 * @property int $id
 * @property int $board_id
 * @property int $user_id
 * @property Carbon $last_viewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read User $user
 */
#[Fillable(['board_id', 'user_id', 'last_viewed_at'])]
class BoardDiscussionView extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_viewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
