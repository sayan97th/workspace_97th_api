<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One admin-initiated "sign in as" session, opened when
 * {@see \App\Http\Controllers\Admin\Impersonation\ImpersonationController::store()} mints a
 * target user's JWT and closed by its {@see \App\Http\Controllers\Admin\Impersonation\ImpersonationController::stop()}.
 * Kept as a durable audit trail (mirroring {@see UserSession}) of who impersonated whom, when,
 * and from where, independent of the app-wide {@see AuditLog} entries also written for the
 * same start/stop events.
 *
 * @property int $id
 * @property int $admin_id
 * @property int $target_user_id
 * @property string|null $impersonation_jti
 * @property string|null $admin_session_jti
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $ended_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $admin
 * @property-read User $targetUser
 */
#[Fillable(['admin_id', 'target_user_id', 'impersonation_jti', 'admin_session_jti', 'ip_address', 'user_agent', 'started_at', 'ended_at', 'ended_reason'])]
class ImpersonationSession extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * The admin who started this session.
     *
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * The account that was impersonated.
     *
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
