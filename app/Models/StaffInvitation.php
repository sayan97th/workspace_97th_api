<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A pending (or resolved) email invitation for a brand-new platform user, sent from
 * Administration > Users > Invite with a role and (optionally) a department pre-assigned
 * before the person even registers. Mirrors {@see WorkspaceInvitation}'s shape, but grants a
 * platform role rather than membership in one workspace. Route-keyed by `code` so the
 * emailed accept link never exposes the numeric id.
 *
 * @property int $id
 * @property string $email
 * @property string $role
 * @property int|null $department_id
 * @property string|null $message
 * @property string $code
 * @property int $invited_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $inviter
 * @property-read Department|null $department
 */
#[Fillable(['email', 'role', 'department_id', 'message', 'invited_by', 'expires_at', 'accepted_at'])]
class StaffInvitation extends Model
{
    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (StaffInvitation $invitation) {
            if (empty($invitation->code)) {
                $invitation->code = Str::random(64);
            }
        });
    }

    /**
     * The admin who sent the invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * The department the invitee will be assigned to, if any.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Determine if the invitation has been accepted.
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Determine if the invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Determine if the invitation can still be accepted.
     */
    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
