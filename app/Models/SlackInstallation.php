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
 * @property int $id
 * @property string $team_id
 * @property string $team_name
 * @property string|null $bot_user_id
 * @property string $bot_token
 * @property string|null $scopes
 * @property int|null $installed_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $installedBy
 * @property-read Collection<int, SlackUserLink> $userLinks
 */
#[Fillable(['team_id', 'team_name', 'bot_user_id', 'bot_token', 'scopes', 'installed_by_id'])]
#[Hidden(['bot_token'])]
class SlackInstallation extends Model
{
    /**
     * The single installation of this single tenant app, or null when Slack is not connected.
     */
    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bot_token' => 'encrypted',
        ];
    }
}
