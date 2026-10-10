<?php

namespace App\Models;

use App\Enums\ExternalProvider;
use App\Enums\ExternalService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member's own Google or Microsoft account, connected from the Automations center ("Connect
 * your Gmail account"). Automations store the account they were created with in
 * `trigger_config.external_account_id` (an email trigger) or `params.external_account_id` (an
 * email or calendar action) and read or send through it on the member's behalf.
 *
 * Both tokens are encrypted at rest and never serialized. `scopes` is what the provider granted,
 * which says which services the account can be used for, see {@see self::supports()}.
 *
 * @property int $id
 * @property int $user_id
 * @property ExternalProvider $provider
 * @property string $provider_user_id
 * @property string|null $email
 * @property string|null $name
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property array<int, string>|null $scopes
 * @property string|null $last_error
 * @property Carbon|null $connected_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id', 'provider', 'provider_user_id', 'email', 'name', 'access_token', 'refresh_token',
    'token_expires_at', 'scopes', 'last_error', 'connected_at', 'last_used_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class ExternalAccount extends Model
{
    /**
     * Whether the provider granted every scope the service needs. Microsoft answers scopes without
     * their resource prefix, Google with the full URL, so both are compared case insensitively.
     */
    public function supports(ExternalService $service): bool
    {
        if ($service->provider() !== $this->provider) {
            return false;
        }

        $granted = array_map(fn (string $scope) => mb_strtolower(preg_replace('#^https://graph\.microsoft\.com/#i', '', $scope) ?? $scope), $this->scopes ?? []);

        foreach ($service->scopes() as $scope) {
            if (! in_array(mb_strtolower($scope), $granted, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The services this account can be used for.
     *
     * @return array<int, string>
     */
    public function serviceValues(): array
    {
        return array_values(array_map(
            fn (ExternalService $service) => $service->value,
            array_filter($this->provider->services(), fn (ExternalService $service) => $this->supports($service)),
        ));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => ExternalProvider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'connected_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
