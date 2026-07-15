<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $mono
 * @property string $color
 * @property string $product
 * @property string $privacy
 * @property bool $is_home
 * @property string|null $description
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, WorkspaceNavigationItem> $navigationItems
 * @property-read Collection<int, WorkspaceNavigationItem> $rootNavigationItems
 * @property-read Collection<int, User> $users
 */
#[Fillable(['name', 'slug', 'mono', 'color', 'product', 'privacy', 'is_home', 'description', 'position'])]
class Workspace extends Model
{
    use SoftDeletes;

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
     * Members of this workspace with their membership metadata.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot(['role', 'is_recent'])
            ->withTimestamps();
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
