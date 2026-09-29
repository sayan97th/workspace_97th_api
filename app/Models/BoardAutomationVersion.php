<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One saved state of a {@see BoardAutomation}, written when it is created and every time its
 * sentence, name, description, importance or failure alert is saved. `snapshot` holds those
 * fields, `changed_parts` which of them differ from the version before (`trigger`, `conditions`,
 * `actions`, `else_actions`, `details`). Restoring a version writes its snapshot back as a new
 * version, so the history is never rewritten.
 *
 * @property int $id
 * @property int $automation_id
 * @property int $version
 * @property array<string, mixed> $snapshot
 * @property array<int, string>|null $changed_parts
 * @property int|null $changed_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BoardAutomation $automation
 * @property-read User|null $changedBy
 */
#[Fillable(['automation_id', 'version', 'snapshot', 'changed_parts', 'changed_by_id'])]
class BoardAutomationVersion extends Model
{
    /** Versions kept per automation, the oldest ones are pruned past this. */
    public const MAX_VERSIONS = 50;

    /** The automation fields a version keeps. */
    public const SNAPSHOT_FIELDS = [
        'name', 'description', 'importance', 'failure_alert', 'trigger_type', 'trigger_column_id', 'trigger_value',
        'trigger_config', 'conditions', 'condition_operator', 'condition_groups', 'actions', 'else_actions',
    ];

    /**
     * @return BelongsTo<BoardAutomation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(BoardAutomation::class, 'automation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'changed_parts' => 'array',
        ];
    }
}
