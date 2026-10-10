<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One email an "email is received" automation already acted on, so the overlap between two polls
 * never imports the same email twice.
 *
 * @property int $id
 * @property int $board_automation_id
 * @property string $message_id
 * @property Carbon|null $received_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['board_automation_id', 'message_id', 'received_at'])]
class BoardAutomationEmailReceipt extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
