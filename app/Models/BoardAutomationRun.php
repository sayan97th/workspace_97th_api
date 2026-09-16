<?php

namespace App\Models;

use App\Services\Board\BoardAutomationService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Marks that `automation_id` already fired for `board_item_id` on `ran_on` —
 * see {@see BoardAutomation::TRIGGER_DATE_ARRIVED} and
 * {@see BoardAutomationService::runDueDateTriggers()}.
 * Exists purely to dedupe the daily scheduled check; a `status_changed`
 * automation never writes here, since it only ever fires once per real value
 * transition already.
 *
 * @property int $id
 * @property int $automation_id
 * @property int $board_item_id
 * @property string $ran_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BoardAutomation $automation
 * @property-read BoardItem $item
 */
#[Fillable(['automation_id', 'board_item_id', 'ran_on'])]
class BoardAutomationRun extends Model
{
    /**
     * @return BelongsTo<BoardAutomation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(BoardAutomation::class, 'automation_id');
    }

    /**
     * @return BelongsTo<BoardItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'board_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ran_on' => 'date',
        ];
    }
}
