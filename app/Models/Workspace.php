<?php

namespace App\Models;

use App\Concerns\HasRandomBigId;
use App\Http\Controllers\Workspace\WorkspaceController;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $invite_code
 * @property string $invite_role
 * @property bool $invite_enabled
 * @property int|null $invite_generated_by
 * @property int|null $created_by
 * @property string $mono
 * @property string $color
 * @property string $product
 * @property string $privacy
 * @property bool $is_home
 * @property bool $is_priority
 * @property string|null $description
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, WorkspaceNavigationItem> $navigationItems
 * @property-read Collection<int, WorkspaceNavigationItem> $rootNavigationItems
 * @property-read WorkspaceNavigationItem|null $manageNavigationItem
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, User> $owners
 * @property-read User|null $creator
 */
#[Fillable(['name', 'slug', 'invite_code', 'invite_role', 'invite_enabled', 'invite_generated_by', 'created_by', 'mono', 'color', 'product', 'privacy', 'is_home', 'is_priority', 'description', 'position'])]
class Workspace extends Model
{
    use HasFactory, HasRandomBigId, SoftDeletes;

    /** The id is a randomly-generated 10-digit number, not an auto-increment. */
    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Workspace $workspace) {
            if (empty($workspace->slug)) {
                $workspace->slug = static::generateUniqueSlug($workspace->name);
            }

            if (empty($workspace->invite_code)) {
                $workspace->invite_code = Str::random(48);
            }
        });

        static::updating(function (Workspace $workspace) {
            if ($workspace->isDirty('name') && ! $workspace->isDirty('slug')) {
                $workspace->slug = static::generateUniqueSlug($workspace->name, $workspace->id);
            }
        });
    }

    /**
     * Generate a slug that is unique across (including soft-deleted) workspaces.
     */
    protected static function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $default_slug = Str::slug($name) ?: 'workspace';

        $query = static::withTrashed()
            ->where(function ($query) use ($default_slug) {
                $query->where('slug', $default_slug)
                    ->orWhere('slug', 'like', $default_slug.'-%');
            });

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $existing_slugs = $query->pluck('slug');

        if ($existing_slugs->isEmpty()) {
            return $default_slug;
        }

        $max_suffix = $existing_slugs
            ->map(function (string $slug) use ($default_slug): ?int {
                if ($slug === $default_slug) {
                    return 0;
                }
                if (preg_match('/^'.preg_quote($default_slug, '/').'-(\d+)$/', $slug, $matches)) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter(fn (?int $suffix) => $suffix !== null)
            ->max() ?? 0;

        return $default_slug.'-'.($max_suffix + 1);
    }

    /**
     * All navigation items belonging to this workspace (flat).
     *
     * @return HasMany<WorkspaceNavigationItem, $this>
     */
    public function navigationItems(): HasMany
    {
        return $this->hasMany(WorkspaceNavigationItem::class);
    }

    /**
     * Top-level navigation items (roots of the tree), ordered for display.
     *
     * @return HasMany<WorkspaceNavigationItem, $this>
     */
    public function rootNavigationItems(): HasMany
    {
        return $this->hasMany(WorkspaceNavigationItem::class)
            ->whereNull('parent_id')
            ->orderBy('position');
    }

    /**
     * This workspace's single "Manage Workspace" entry point — a root-level
     * navigation leaf seeded once per workspace (see
     * {@see WorkspaceController::store()})
     * whose `view_key` is the reserved `"workspace_manage"` string. Lets the
     * frontend resolve `/workspaces/{workspace_id}/...` routes straight to
     * their underlying board id without walking the whole navigation tree.
     *
     * @return HasOne<WorkspaceNavigationItem, $this>
     */
    public function manageNavigationItem(): HasOne
    {
        return $this->hasOne(WorkspaceNavigationItem::class)->where('view_key', 'workspace_manage');
    }

    /**
     * Members of this workspace with their membership metadata.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot(['role', 'is_recent', 'invited_by'])
            ->withTimestamps();
    }

    /**
     * Members with the "owner" role, shown as a board's owners in its info popover
     * (boards don't have their own owner list, so they inherit the workspace's).
     *
     * @return BelongsToMany<User, $this>
     */
    public function owners(): BelongsToMany
    {
        return $this->users()->wherePivot('role', 'owner');
    }

    /**
     * Email invitations sent for this workspace (pending, expired or accepted).
     *
     * @return HasMany<WorkspaceInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    /**
     * The user who originally created this workspace. Permanent — unlike the
     * "owner" role (which can be transferred), this never changes, so it's
     * what {@see isCreator()} uses to block that person from ever being
     * removed from their own workspace.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the given user is this workspace's original creator.
     */
    public function isCreator(int $user_id): bool
    {
        return $this->created_by !== null && $this->created_by === $user_id;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'is_priority' => 'boolean',
            'invite_enabled' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
