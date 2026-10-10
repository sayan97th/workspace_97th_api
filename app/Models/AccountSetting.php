<?php

namespace App\Models;

use App\Services\Board\AutomationUsageMeter;
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
 * @property string|null $default_timezone
 * @property string $default_language
 * @property string $default_date_format
 * @property string $default_time_format
 * @property string $default_first_day_of_week
 * @property array<string, array<string, bool>>|null $account_permissions
 * @property int|null $automation_monthly_action_limit how many automation actions may run per calendar month, null for no limit, see {@see AutomationUsageMeter}
 * @property string|null $company_legal_name
 * @property string|null $company_tagline
 * @property string|null $company_description
 * @property string|null $company_industry
 * @property string|null $company_size
 * @property int|null $company_founded_year
 * @property string|null $company_website
 * @property string|null $support_email
 * @property string|null $support_url
 * @property string|null $contact_phone
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $address_city
 * @property string|null $address_state
 * @property string|null $address_postal_code
 * @property string|null $address_country
 * @property array<string, string>|null $social_links
 * @property string|null $logo_dark_path
 * @property string|null $favicon_path
 * @property string|null $brand_color
 * @property bool $show_name_in_top_bar
 * @property string|null $login_headline
 * @property string|null $login_message
 * @property bool $announcement_enabled
 * @property string|null $announcement_message
 * @property string $announcement_tone
 * @property string|null $announcement_link_label
 * @property string|null $announcement_link_url
 * @property bool $announcement_dismissible
 * @property Carbon|null $announcement_published_at
 * @property-read string|null $logo_url
 * @property-read string|null $logo_dark_url
 * @property-read string|null $favicon_url
 * @property-read string|null $email_header_url
 * @property-read User|null $panicModeActivator
 */
#[Fillable([
    'account_name', 'account_url', 'weekend_start', 'show_weekends', 'home_page',
    'default_timezone', 'default_language', 'default_date_format', 'default_time_format', 'default_first_day_of_week',
    'account_permissions', 'automation_monthly_action_limit',
    'logo_path', 'email_header_path',
    'company_legal_name', 'company_tagline', 'company_description', 'company_industry',
    'company_size', 'company_founded_year', 'company_website',
    'support_email', 'support_url', 'contact_phone',
    'address_line_1', 'address_line_2', 'address_city', 'address_state', 'address_postal_code', 'address_country',
    'social_links', 'logo_dark_path', 'favicon_path', 'brand_color', 'show_name_in_top_bar',
    'login_headline', 'login_message',
    'announcement_enabled', 'announcement_message', 'announcement_tone',
    'announcement_link_label', 'announcement_link_url', 'announcement_dismissible', 'announcement_published_at',
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
     * Organization branding images keyed by the `{asset}` route segment of
     * `/api/admin/organization/assets/{asset}`, each mapped to the column holding its storage path.
     */
    public const BRANDING_ASSET_COLUMNS = [
        'logo' => 'logo_path',
        'logo_dark' => 'logo_dark_path',
        'favicon' => 'favicon_path',
    ];

    public const SOCIAL_NETWORKS = ['linkedin', 'x', 'facebook', 'instagram', 'youtube'];

    public const ANNOUNCEMENT_TONES = ['info', 'success', 'warning', 'critical'];

    public const COMPANY_SIZES = ['1-10', '11-50', '51-200', '201-500', '501-1000', '1001+'];

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
            'account_permissions' => 'array',
            'automation_monthly_action_limit' => 'integer',
            'company_founded_year' => 'integer',
            'social_links' => 'array',
            'show_name_in_top_bar' => 'boolean',
            'announcement_enabled' => 'boolean',
            'announcement_dismissible' => 'boolean',
            'announcement_published_at' => 'datetime',
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
            get: fn () => $this->logo_path ? Storage::disk(config('filesystems.app_disk'))->url($this->logo_path) : null,
        );
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function emailHeaderUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->email_header_path ? Storage::disk(config('filesystems.app_disk'))->url($this->email_header_path) : null,
        );
    }

    /**
     * The logo variant shown while the app runs in dark mode, falls back to the light logo on the client.
     *
     * @return Attribute<string|null, never>
     */
    protected function logoDarkUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->logo_dark_path ? Storage::disk(config('filesystems.app_disk'))->url($this->logo_dark_path) : null,
        );
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function faviconUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->favicon_path ? Storage::disk(config('filesystems.app_disk'))->url($this->favicon_path) : null,
        );
    }

    /**
     * The announcement banner every signed in user sees, or null while it is turned off or empty.
     *
     * @return array{message: string, tone: string, link_label: string|null, link_url: string|null, is_dismissible: bool, published_at: string|null}|null
     */
    public function activeAnnouncement(): ?array
    {
        if (! $this->announcement_enabled || blank($this->announcement_message)) {
            return null;
        }

        return [
            'message' => $this->announcement_message,
            'tone' => $this->announcement_tone,
            'link_label' => $this->announcement_link_label,
            'link_url' => $this->announcement_link_url,
            'is_dismissible' => $this->announcement_dismissible,
            'published_at' => $this->announcement_published_at?->toIso8601String(),
        ];
    }
}
