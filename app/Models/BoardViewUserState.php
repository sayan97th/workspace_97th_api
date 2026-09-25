<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user's remembered, unsaved toolbar changes to one board view ("Remember
 * my filters"). Only that user sees them; the view's own saved state, which
 * everyone else keeps seeing, is untouched. See
 * {@link \App\Http\Controllers\Board\BoardViewController::updatePersonalState()}.
 *
 * @property int $id
 * @property int $user_id
 * @property int $board_view_id
 * @property array<string, mixed>|null $filter_state
 * @property array<int, array<string, mixed>>|null $sort_state
 * @property array<int, string>|null $hidden_column_ids
 * @property string|null $group_by_option_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read BoardView $boardView
 */
#[Fillable(['user_id', 'board_view_id', 'filter_state', 'sort_state', 'hidden_column_ids', 'group_by_option_id'])]
class BoardViewUserState extends Model
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
        return $this->belongsTo(BoardView::class);
    }

    /**
     * The shape the toolbar replays. Built as a plain array (not a JsonResource)
     * so a `quick_filter_selections` map keyed by column ids keeps its keys.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'filter_state' => $this->filter_state,
            'sort_state' => $this->sort_state,
            'hidden_column_ids' => $this->hidden_column_ids,
            'group_by_option_id' => $this->group_by_option_id,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filter_state' => 'array',
            'sort_state' => 'array',
            'hidden_column_ids' => 'array',
        ];
    }
}
