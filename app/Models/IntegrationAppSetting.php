<?php

namespace App\Models;

use App\Enums\ExternalProvider;
use App\Services\ExternalAccounts\ExternalAppCredentials;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The OAuth app of one provider (Google or Microsoft), saved by an administrator from
 * Administration > Integrations. The secret is encrypted at rest and never serialized, see
 * {@see ExternalAppCredentials} for how it is read.
 *
 * @property int $id
 * @property ExternalProvider $provider
 * @property string $client_id
 * @property string $client_secret
 * @property string|null $tenant_id
 * @property string|null $redirect_uri
 * @property int|null $updated_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $updatedBy
 */
#[Fillable(['provider', 'client_id', 'client_secret', 'tenant_id', 'redirect_uri', 'updated_by_id'])]
#[Hidden(['client_secret'])]
class IntegrationAppSetting extends Model
{
    public static function forProvider(ExternalProvider $provider): ?self
    {
        return static::query()->where('provider', $provider->value)->first();
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
            'provider' => ExternalProvider::class,
            'client_secret' => 'encrypted',
        ];
    }
}
