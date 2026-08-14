<?php

namespace App\Models;

use App\Concerns\IssuesJwtTokens;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One continuous JWT-backed sign-in session (device/browser) for a user, powering
 * My Profile > Session history. A row is created at login/register/2FA and its `jti`
 * is rotated in place on every silent token refresh, so it represents one continuous
 * session rather than one row per refresh. See {@see IssuesJwtTokens}.
 *
 * @property int $id
 * @property int $user_id
 * @property string $jti
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $device_label
 * @property Carbon $last_used_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'jti', 'ip_address', 'user_agent', 'device_label', 'last_used_at', 'expires_at', 'revoked_at'])]
class UserSession extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
