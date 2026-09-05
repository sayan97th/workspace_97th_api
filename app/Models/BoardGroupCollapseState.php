<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user's personal collapse/expand state for the tables (`BoardGroup`) on
 * one tab (`BoardView`) — a viewer-scoped preference, mirroring
 * {@link BoardViewUserOrder}'s per-user tab order. Only the ids of the
 * *collapsed* groups are stored (not one row/flag per group), which is what
 * keeps this cheap to read/write even for a board with hundreds of tables,
 * most of which stay expanded. See
 * {@link \App\Http\Controllers\Board\BoardGroupController::updateCollapsedState()}.
 *
 * @property int $id
 * @property int $user_id
 * @property int $board_view_id
 * @property array<int, int> $collapsed_group_ids
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read BoardView $boardView
 */
#[Fillable(['user_id', 'board_view_id', 'collapsed_group_ids'])]
class BoardGroupCollapseState extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<BoardView, $this>
     */
    public function boardView(): BelongsTo
    {
        return $this->belongsTo(BoardView::class, 'board_view_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collapsed_group_ids' => 'array',
        ];
    }
}
