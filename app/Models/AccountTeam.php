<?php

namespace App\Models;

use Database\Factories\AccountTeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An account-wide group of staff users (Monday-style "Teams"), independent of any
 * single {@see Workspace} — created from the account menu's Teams directory to
 * organize the org chart, not to gate access to boards.
 *
 * @property int $id
 * @property string $name
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $creator
 * @property-read Collection<int, User> $members
 * @property-read int|null $members_count
 */
#[Fillable(['name', 'created_by_id'])]
class AccountTeam extends Model
{
    /** @use HasFactory<AccountTeamFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The user who created this team.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The staff users assigned to this team.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_team_user')
            ->withPivot('is_team_owner')
            ->withTimestamps();
    }

    /**
     * The members delegated to manage this team's roster without full admin access.
     *
     * @return BelongsToMany<User, $this>
     */
    public function owners(): BelongsToMany
    {
        return $this->members()->wherePivot('is_team_owner', true);
    }

    /**
     * Whether the given user may add or remove this team's members: account admins always,
     * otherwise only one of the team's owners.
     */
    public function canBeManagedBy(User $user): bool
    {
        if ($user->hasRole(['super_admin', 'admin'])) {
            return true;
        }

        return $this->owners()->whereKey($user->id)->exists();
    }
}
