<?php

namespace App\Models;

use App\Services\Board\BoardItemActivityService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to a single cell of a board item (status, date, person, text and
 * so on), recorded by {@see BoardItemActivityService}. The
 * Update Feed weaves these between an item's updates, so `old_display` and
 * `new_display` keep the human readable text the change had when it happened
 * (a status label, a person's name), which stays correct even after the
 * column is later renamed or its options are edited. Distinct from
 * {@see BoardActivityLog}, which only tracks board lifecycle events.
 *
 * @property int $id
 * @property int $item_id
 * @property int|null $user_id
 * @property int|null $column_id
 * @property string $column_label
 * @property string $column_type
 * @property string|null $old_display
 * @property string|null $new_display
 * @property Carbon $created_at
 * @property-read BoardItem $item
 * @property-read User|null $user
 */
#[Fillable(['item_id', 'user_id', 'column_id', 'column_label', 'column_type', 'old_display', 'new_display'])]
class BoardItemActivity extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<BoardItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
