<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Singleton row (always `id = 1`) holding every account-wide Administration setting:
 * Profile (account name/URL), Account preferences, Branding file paths, Authentication
 * policy, and Advanced settings. Single-tenant app, so "the account" is the whole
 * instance, not a per-customer record — use {@see AccountSetting::current()} rather than
 * querying the table directly.
 *
 * @property int $id
 * @property string $account_name
 * @property string $account_url
 * @property string $weekend_start
 * @property bool $show_weekends
 * @property string $home_page
 * @property string|null $logo_path
 * @property string|null $email_header_path
 * @property bool $two_factor_enforced
 * @property bool $google_sso_enabled
 * @property bool $saml_sso_enabled
 * @property array<string, mixed>|null $saml_metadata
 * @property bool $scim_enabled
 * @property string|null $scim_token
 * @property bool $guest_approval_enabled
 * @property array<int, string>|null $approved_domains
 * @property bool $ip_restriction_enabled
 * @property array<int, string>|null $ip_ranges
 * @property string|null $default_product
 * @property int|null $session_inactivity_minutes
 * @property int|null $session_max_duration_minutes
 * @property bool $panic_mode_active
 * @property Carbon|null $panic_mode_activated_at
 * @property int|null $panic_mode_activated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $logo_url
 * @property-read string|null $email_header_url
 * @property-read User|null $panicModeActivator
 */
#[Fillable([
    'account_name', 'account_url', 'weekend_start', 'show_weekends', 'home_page',
    'logo_path', 'email_header_path',
    'two_factor_enforced', 'google_sso_enabled', 'saml_sso_enabled', 'saml_metadata',
    'scim_enabled', 'scim_token', 'guest_approval_enabled', 'approved_domains',
    'ip_restriction_enabled', 'ip_ranges', 'default_product',
    'session_inactivity_minutes', 'session_max_duration_minutes',
    'panic_mode_active', 'panic_mode_activated_at', 'panic_mode_activated_by',
])]
#[Appends(['logo_url', 'email_header_url'])]
class AccountSetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_weekends' => 'boolean',
            'saml_metadata' => 'array',
            'scim_enabled' => 'boolean',
            'guest_approval_enabled' => 'boolean',
            'approved_domains' => 'array',
            'ip_restriction_enabled' => 'boolean',
            'ip_ranges' => 'array',
            'two_factor_enforced' => 'boolean',
            'google_sso_enabled' => 'boolean',
            'saml_sso_enabled' => 'boolean',
            'panic_mode_active' => 'boolean',
            'panic_mode_activated_at' => 'datetime',
        ];
    }

    /**
     * The one settings row, created on first access with sane defaults.
     *
     * Looks up "the first row that exists" rather than hardcoding `id = 1`: auto-increment
     * never reuses an id, so if this row were ever deleted and recreated its id would no
     * longer be 1, and a hardcoded lookup would both miss the existing row and collide with
     * it on `account_url`'s unique constraint when trying to insert a new one.
     *
     * `create()` only populates the attributes it was given on the in-memory model it
     * returns, columns left to their database-level default (like `weekend_start`) would
     * read back as null until some later write touched the row, so a freshly created row is
     * re-fetched to pick up every column's real, saved value.
     */
    public static function current(): self
    {
        $settings = static::query()->first();
        if ($settings) {
            return $settings;
        }

        return static::create([
            'account_name' => '97th Floor',
            'account_url' => '97thfloor',
        ])->fresh();
    }

    /**
     * The admin who most recently activated panic mode, if it's currently active.
     *
     * @return BelongsTo<User, $this>
     */
    public function panicModeActivator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'panic_mode_activated_by');
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
        );
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function emailHeaderUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->email_header_path ? Storage::disk('public')->url($this->email_header_path) : null,
        );
    }
}
