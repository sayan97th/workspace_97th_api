<?php

namespace App\Models;

use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One immutable row of the account's audit trail — who did what, from where. Written
 * exclusively through {@see AuditLogger}, never updated after creation.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $event
 * @property string $description
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $actor
 */
#[Fillable(['user_id', 'event', 'description', 'ip_address', 'user_agent', 'metadata'])]
class AuditLog extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * The user who performed the audited action, if any (some events, like a failed login
     * by an unknown email, have no authenticated actor).
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
