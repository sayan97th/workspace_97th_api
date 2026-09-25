<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The last time a user opened a board. Powers the Home page's
 * "Recently visited" list.
 *
 * @property int $id
 * @property int $user_id
 * @property int $board_id
 * @property Carbon $visited_at
 * @property-read WorkspaceNavigationItem $board
 */
#[Fillable(['user_id', 'board_id', 'visited_at'])]
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
        ];
    }
}
