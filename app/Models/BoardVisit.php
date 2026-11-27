<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The last time a user opened a board. Powers the Home page's
 * "Recently visited" list and the board Activity log's "Last viewed" tab.
 *
 * @property int $id
 * @property int $user_id
 * @property int $board_id
 * @property int|null $board_view_id
 * @property Carbon $visited_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardView|null $boardView
 * @property-read User|null $user
 */
#[Fillable(['user_id', 'board_id', 'board_view_id', 'visited_at'])]
class BoardVisit extends Model
{
    /**
     * Records `$user` opening `$board` right now.
     */
    public static function record(int $user_id, int $board_id): void
    {
        static::query()->updateOrCreate(
            ['user_id' => $user_id, 'board_id' => $board_id],
            ['visited_at' => now()]
        );
    }

    /**
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * Records the tab `$user` has open on `$board`, which the Home card
     * previews. Also counts as a visit, so switching tabs keeps the board
     * at the top of "Recently visited".
     */
    public static function recordView(int $user_id, int $board_id, int $board_view_id): void
    {
        static::query()->updateOrCreate(
            ['user_id' => $user_id, 'board_id' => $board_id],
            ['board_view_id' => $board_view_id, 'visited_at' => now()]
        );
    }

    /**
     * The tab open on the last visit, null when unknown or deleted.
     *
     * @return BelongsTo<BoardView, $this>
     */
    public function boardView(): BelongsTo
    {
        return $this->belongsTo(BoardView::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
        ];
    }
}
