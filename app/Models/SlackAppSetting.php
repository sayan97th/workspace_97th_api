<?php

namespace App\Models;

use App\Services\Slack\SlackAppCredentials;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The Slack app credentials an administrator saved from Administration > Integrations.
 * Both secrets are encrypted at rest and never serialized, see
 * {@see SlackAppCredentials} for how they are read.
 *
 * @property int $id
 * @property string $client_id
 * @property string $client_secret
 * @property string|null $signing_secret
 * @property string|null $redirect_uri
 * @property int|null $updated_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $updatedBy
 */
#[Fillable(['client_id', 'client_secret', 'signing_secret', 'redirect_uri', 'updated_by_id'])]
#[Hidden(['client_secret', 'signing_secret'])]
class SlackAppSetting extends Model
{
    /**
     * The single settings row, or null while the environment values are used.
     */
    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
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
        ];
    }
}
