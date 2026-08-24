<?php

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An account-wide organizational unit users are assigned to, one per user, used by the
 * Administration Users/Departments sections for headcount and seat-limit tracking.
 * Distinct from {@see AccountTeam} (an arbitrary many-to-many collaboration grouping): a
 * user has at most one department but can belong to many account teams.
 *
 * @property int $id
 * @property string $name
 * @property int|null $seat_limit
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $creator
 * @property-read int|null $users_count
 */
#[Fillable(['name', 'seat_limit', 'created_by_id'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The admin who created this department.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The users currently assigned to this department.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
