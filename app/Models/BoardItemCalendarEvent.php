<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The Google Calendar event a "create an event in Google Calendar" automation keeps for one item,
 * so later changes to the item update the same event and deleting the item removes it.
 *
 * @property int $id
 * @property int $board_automation_id
 * @property int $board_item_id
 * @property int $external_account_id
 * @property string $calendar_id
 * @property string $event_id
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ExternalAccount $account
 */
#[Fillable(['board_automation_id', 'board_item_id', 'external_account_id', 'calendar_id', 'event_id', 'synced_at'])]
class BoardItemCalendarEvent extends Model
{
    /**
     * @return BelongsTo<ExternalAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ExternalAccount::class, 'external_account_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }
}
