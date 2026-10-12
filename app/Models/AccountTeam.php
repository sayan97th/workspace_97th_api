<?php

namespace App\Models;

use App\Http\Resources\AccountTeamResource;
use App\Services\AccountTeam\AccountTeamBoardAccess;
use Database\Factories\AccountTeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * An account-wide group of staff users (Monday-style "Teams"), independent of any
 * single {@see Workspace}, managed from the Teams page. Teams nest as sub-teams,
 * may allow guests (client accounts), and can join boards, which gives every
 * member access to them (see {@see AccountTeamBoardAccess}).
 *
 * @property int $id
 * @property string $name
 * @property int|null $parent_id
 * @property bool $allow_guests
 * @property string|null $picture_path
 * @property string|null $cover_path
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $creator
 * @property-read Collection<int, User> $members
 * @property-read int|null $members_count
 * @property-read AccountTeam|null $parent
 * @property-read Collection<int, AccountTeam> $subTeams
 * @property-read Collection<int, WorkspaceNavigationItem> $boards
 * @property-read string|null $picture_url
 * @property-read string|null $cover_url
 */
#[Fillable(['name', 'parent_id', 'allow_guests', 'picture_path', 'cover_path', 'created_by_id'])]
class AccountTeam extends Model
{
    /** @use HasFactory<AccountTeamFactory> */
    use HasFactory, SoftDeletes;

    /** Platform roles every team accepts. */
    public const STAFF_ROLES = ['super_admin', 'admin', 'staff'];

    /** The role a guest holds, accepted only on a team that allows guests. */
    public const GUEST_ROLE = 'client';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allow_guests' => 'boolean',
        ];
    }

    /**
     * Counts and relations {@see AccountTeamResource} reads, loaded in one
     * place so every endpoint that returns a team stays free of N+1 queries.
     */
    public function loadResourceData(?User $viewer): self
    {
        $this->loadCount(['members', 'subTeams', 'boards'])->load('owners');
        if ($viewer !== null) {
            $this->loadExists(['members as is_member' => fn ($query) => $query->whereKey($viewer->id)]);
        }

        return $this;
    }

    /**
     * Same as {@see self::loadResourceData()} for a list query.
     *
     * @param  Builder<AccountTeam>  $query
     * @return Builder<AccountTeam>
     */
    public function scopeWithResourceData(Builder $query, ?User $viewer): Builder
    {
        $query->withCount(['members', 'subTeams', 'boards'])->with('owners');
        if ($viewer !== null) {
            $query->withExists(['members as is_member' => fn ($inner) => $inner->whereKey($viewer->id)]);
        }

        return $query;
    }

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
     * The team this one is a sub-team of.
     *
     * @return BelongsTo<AccountTeam, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Teams nested directly under this one.
     *
     * @return HasMany<AccountTeam, $this>
     */
    public function subTeams(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * The boards this team joined, shown on its Content tab.
     *
     * @return BelongsToMany<WorkspaceNavigationItem, $this>
     */
    public function boards(): BelongsToMany
    {
        return $this->belongsToMany(WorkspaceNavigationItem::class, 'account_team_boards', 'account_team_id', 'board_id')
            ->withPivot('added_by_id')
            ->withTimestamps();
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

    /**
     * Platform roles this team's members may hold, guests only when the team allows them.
     *
     * @return array<int, string>
     */
    public function memberRoles(): array
    {
        return $this->allow_guests ? [...self::STAFF_ROLES, self::GUEST_ROLE] : self::STAFF_ROLES;
    }

    /**
     * Ids of every team nested under this one at any depth, used to refuse a parent change
     * that would make a team its own ancestor.
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $descendant_ids = [];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $frontier = self::whereIn('parent_id', $frontier)->pluck('id')->all();
            $frontier = array_values(array_diff($frontier, $descendant_ids));
            $descendant_ids = [...$descendant_ids, ...$frontier];
        }

        return $descendant_ids;
    }

    protected function pictureUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->picture_path ? Storage::disk(config('filesystems.app_disk'))->url($this->picture_path) : null,
        );
    }

    protected function coverUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cover_path ? Storage::disk(config('filesystems.app_disk'))->url($this->cover_path) : null,
        );
    }
}
