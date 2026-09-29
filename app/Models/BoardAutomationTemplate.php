<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An automation saved with "Save as template". `definition` holds everything a new automation is
 * built from (`trigger_type`, `trigger_column_id`, `trigger_value`, `trigger_config`, `conditions`,
 * `actions`), but none of its state (enabled, owner, runs).
 *
 * @property int $id
 * @property int $board_id
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $definition
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 */
#[Fillable(['board_id', 'name', 'description', 'definition', 'created_by_id'])]
class BoardAutomationTemplate extends Model
{
    /**
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }
}
