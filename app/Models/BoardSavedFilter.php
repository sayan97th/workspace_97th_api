<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A personal, named filter one user saved on a board (the Filter panel's
 * "Saved filters"). Private to its owner, see
 * {@link \App\Http\Controllers\Board\BoardSavedFilterController}.
 *
 * @property int $id
 * @property int $user_id
 * @property int $board_id
 * @property string $name
 * @property array<string, mixed> $filter_state
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read WorkspaceNavigationItem $board
 */
#[Fillable(['user_id', 'board_id', 'name', 'filter_state'])]
class BoardSavedFilter extends Model
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
     * Built as a plain array (not a JsonResource) so a `quick_filter_selections`
     * map keyed by column ids keeps its keys.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'filter_state' => $this->filter_state,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filter_state' => 'array',
        ];
    }
}
