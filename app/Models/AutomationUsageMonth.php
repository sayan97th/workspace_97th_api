<?php

namespace App\Models;

use App\Services\Board\AutomationUsageMeter;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How many automation actions ran in one calendar month (`YYYY-MM`, in the app's time zone),
 * counted against the account's monthly limit, see {@see AutomationUsageMeter}.
 *
 * @property int $id
 * @property string $month
 * @property int $action_count
 * @property int $warned_percent the highest usage warning already sent this month, 0, 80 or 100
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['month', 'action_count', 'warned_percent'])]
class AutomationUsageMonth extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action_count' => 'integer',
            'warned_percent' => 'integer',
        ];
    }
}
