<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The account's connection to one Slack workspace, created when an administrator
 * completes the "Add to Slack" OAuth flow. `bot_token` is encrypted at rest and never
 * serialized, it only ever leaves the server inside an `Authorization` header sent to Slack.
 *
 * Several workspaces can stay connected, like on monday.com, but exactly one is active at a
 * time: notifications, automations and "Connect my Slack" all use the active one.
 *
 * @property int $id
 * @property string $team_id
 * @property string $team_name
 * @property string|null $team_url
 * @property bool $is_active
 * @property string|null $bot_user_id
 * @property string|null $app_id
 * @property string $bot_token
 * @property string|null $scopes
 * @property int|null $installed_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $installedBy
 * @property-read Collection<int, SlackUserLink> $userLinks
 * @property-read Collection<int, SlackConnection> $connections
 */
#[Fillable(['team_id', 'team_name', 'team_url', 'is_active', 'bot_user_id', 'app_id', 'bot_token', 'scopes', 'installed_by_id'])]
#[Hidden(['bot_token'])]
class SlackInstallation extends Model
{
    /**
     * The active workspace, or null when Slack is not connected.
     */
    public static function current(): ?self
    {
        return static::query()->where('is_active', true)->latest('id')->first();
    }

    /**
     * The first workspace ever connected becomes the active one on its own, so connecting
     * Slack for the first time needs no extra "make active" step.
     */
    protected static function booted(): void
    {
        static::creating(function (self $installation) {
            if ($installation->getAttribute('is_active') === null) {
                $installation->is_active = ! static::query()->where('is_active', true)->exists();
            }
        });
    }

    /**
     * Whether the Slack app was granted `$scope` in this workspace.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, array_map('trim', explode(',', (string) $this->scopes)), true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by_id');
    }

    /**
     * @return HasMany<SlackUserLink, $this>
     */
    public function userLinks(): HasMany
    {
        return $this->hasMany(SlackUserLink::class);
    }

    /**
     * The members' own account connections made in this workspace from the Automations center.
     *
     * @return HasMany<SlackConnection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(SlackConnection::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bot_token' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }
}
