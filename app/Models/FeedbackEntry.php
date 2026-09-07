<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One "Give feedback" submission from the board options menu — a free-form
 * note about the product, optionally tied to the board/page the user was on
 * when they sent it.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $board_id
 * @property string $message
 * @property string|null $page_url
 * @property Carbon|null $created_at
 * @property-read User|null $user
 * @property-read WorkspaceNavigationItem|null $board
 */
#[Fillable(['user_id', 'board_id', 'message', 'page_url'])]
class FeedbackEntry extends Model
{
    protected $table = 'feedback_entries';

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
}
