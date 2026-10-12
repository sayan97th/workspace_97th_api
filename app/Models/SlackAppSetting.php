<?php

namespace App\Models;

use App\Services\Slack\SlackAppCredentials;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Slack app an administrator saved from Administration > Integrations > Slack, for example the
 * Make It Simple app. Several can be saved next to the app in the API environment, `is_active`
 * marks the selected one. Both secrets are encrypted at rest and never serialized, see
 * {@see SlackAppCredentials} for which app is used.
 *
 * @property int $id
 * @property string|null $app_id
 * @property string|null $label
 * @property bool $is_active
 * @property string $client_id
 * @property string $client_secret
 * @property string|null $signing_secret
 * @property string|null $redirect_uri
 * @property int|null $updated_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $updatedBy
 */
#[Fillable(['app_id', 'label', 'is_active', 'client_id', 'client_secret', 'signing_secret', 'redirect_uri', 'updated_by_id'])]
#[Hidden(['client_secret', 'signing_secret'])]
class SlackAppSetting extends Model
{
    /**
     * The selected saved app, or the first one saved when none is selected. Whether a saved app is
     * in use at all also depends on the environment, see {@see SlackAppCredentials::activeSetting()}.
     */
    public static function current(): ?self
    {
        return static::query()->orderByDesc('is_active')->oldest('id')->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'signing_secret' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }
}
