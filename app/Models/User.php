<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasRoles;
use App\Concerns\HasTeams;
use App\Jobs\SendEmailJob;
use App\Mail\PasswordResetMail;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

/**
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string|null $google_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $phone
 * @property string|null $job_title
 * @property int|null $department_id
 * @property string|null $timezone
 * @property string|null $profile_photo_path
 * @property bool $is_active
 * @property string|null $working_status
 * @property string|null $working_status_dates
 * @property bool $disable_notifications_while_away
 * @property bool $hide_online_status
 * @property array<string, bool>|null $notification_preferences
 * @property bool $quiet_hours_enabled
 * @property string $quiet_hours_start
 * @property string $quiet_hours_end
 * @property string $email_digest_frequency
 * @property bool $desktop_notifications_enabled
 * @property bool $notification_sound_enabled
 * @property bool $tab_badge_enabled
 * @property bool $auto_follow_enabled
 * @property string $language
 * @property string $time_format
 * @property string $date_format
 * @property string $first_day_of_week
 * @property int|null $sidebar_width
 * @property array<string, mixed>|null $sidebar_preferences
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property int|null $last_active_workspace_id
 * @property bool $excluded_from_home_workspace
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $full_name
 * @property-read bool $is_deactivated
 * @property-read string|null $profile_photo_url
 * @property-read Team|null $currentTeam
 * @property-read Workspace|null $lastActiveWorkspace
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Role> $roles
 * @property-read Collection<int, Workspace> $workspaces
 * @property-read Collection<int, AccountTeam> $accountTeams
 * @property-read Collection<int, UserSession> $sessions
 * @property-read Department|null $department
 * @property-read Collection<int, UserProfileFieldValue> $profileFieldValues
 * @property-read SlackUserLink|null $slackLink
 */
#[Fillable([
    'first_name', 'last_name', 'email', 'google_id', 'password', 'current_team_id', 'last_active_workspace_id', 'phone', 'job_title', 'department_id', 'timezone', 'profile_photo_path', 'is_active',
    'working_status', 'working_status_dates', 'disable_notifications_while_away', 'hide_online_status',
    'notification_preferences', 'desktop_notifications_enabled', 'notification_sound_enabled', 'tab_badge_enabled', 'auto_follow_enabled',
    'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'email_digest_frequency',
    'language', 'time_format', 'date_format', 'first_day_of_week', 'sidebar_width', 'sidebar_preferences',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
#[Appends(['full_name', 'profile_photo_url', 'is_deactivated'])]
class User extends Authenticatable implements JWTSubject, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasTeams, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The model's default attribute values.
     *
     * Mirrors the `is_active` column's database default so a freshly
     * instantiated (unsaved) user is already considered active in memory.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Account defaults (Administration > Account) a brand new user starts with. Only fills
     * the locale preferences the creating code did not set itself, and never creates the
     * settings row, so factories and tests without one keep the column defaults.
     */
    private const ACCOUNT_DEFAULT_ATTRIBUTES = [
        'timezone' => 'default_timezone',
        'language' => 'default_language',
        'date_format' => 'default_date_format',
        'time_format' => 'default_time_format',
        'first_day_of_week' => 'default_first_day_of_week',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $settings = AccountSetting::query()->first();
            if (! $settings) {
                return;
            }

            foreach (self::ACCOUNT_DEFAULT_ATTRIBUTES as $attribute => $setting_key) {
                if ($user->getAttribute($attribute) === null && $settings->{$setting_key} !== null) {
                    $user->setAttribute($attribute, $settings->{$setting_key});
                }
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
            'disable_notifications_while_away' => 'boolean',
            'hide_online_status' => 'boolean',
            'notification_preferences' => 'array',
            'sidebar_preferences' => 'array',
            'desktop_notifications_enabled' => 'boolean',
            'notification_sound_enabled' => 'boolean',
            'tab_badge_enabled' => 'boolean',
            'auto_follow_enabled' => 'boolean',
            'quiet_hours_enabled' => 'boolean',
            'excluded_from_home_workspace' => 'boolean',
        ];
    }

    /**
     * Whether `$now` falls inside this user's Do Not Disturb window, read as
     * wall-clock times in their own `timezone` (UTC when unset). A window that
     * ends before it starts (22:00 to 07:00) spans midnight.
     */
    public function isInQuietHours(?CarbonInterface $now = null): bool
    {
        if (! $this->quiet_hours_enabled) {
            return false;
        }

        $local_now = ($now ?? now())->copy()->setTimezone($this->timezone ?: 'UTC');
        $current_time = $local_now->format('H:i');

        if ($this->quiet_hours_start === $this->quiet_hours_end) {
            return false;
        }

        return $this->quiet_hours_start < $this->quiet_hours_end
            ? $current_time >= $this->quiet_hours_start && $current_time < $this->quiet_hours_end
            : $current_time >= $this->quiet_hours_start || $current_time < $this->quiet_hours_end;
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Get the custom claims to add to the JWT.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'roles' => $this->roles->pluck('name')->toArray(),
        ];
    }

    /**
     * Get the URL to the user's profile photo.
     *
     * @return Attribute<string|null, never>
     */
    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->profile_photo_path
                ? Storage::disk(config('filesystems.app_disk'))->url($this->profile_photo_path)
                : null,
        );
    }

    /**
     * The workspaces this user is a member of.
     *
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->withPivot(['role', 'is_recent'])
            ->withTimestamps();
    }

    /**
     * The workspace the user last had open, restored by the frontend switcher
     * on login/page reload so it doesn't always fall back to the home
     * workspace. Written by the "activate workspace" endpoint.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function lastActiveWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'last_active_workspace_id');
    }

    /**
     * Records the given workspace as the one the user last had open.
     */
    public function setLastActiveWorkspace(Workspace $workspace): void
    {
        $this->update(['last_active_workspace_id' => $workspace->id]);
        $this->setRelation('lastActiveWorkspace', $workspace);
    }

    /**
     * Whether this account can no longer sign in, either because an administrator disabled
     * it (`is_active` is false) or deleted it (soft deleted). Either way the row is kept, so
     * the person's past comments and assignments stay attributed to them, and the frontend
     * shows their name and avatar faded instead of a generic "Deleted user".
     *
     * @return Attribute<bool, never>
     */
    protected function isDeactivated(): Attribute
    {
        return Attribute::make(
            get: fn () => ! $this->is_active || $this->trashed(),
        );
    }

    /**
     * Get the user's full name, combining their first and last name.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => trim("{$this->first_name} {$this->last_name}"),
        );
    }

    /**
     * The account-wide {@see AccountTeam}s this user has been assigned to.
     *
     * @return BelongsToMany<AccountTeam, $this>
     */
    public function accountTeams(): BelongsToMany
    {
        return $this->belongsToMany(AccountTeam::class, 'account_team_user')
            ->withTimestamps();
    }

    /**
     * This user's own Slack account link, present once they have used "Connect my Slack".
     *
     * @return HasOne<SlackUserLink, $this>
     */
    public function slackLink(): HasOne
    {
        return $this->hasOne(SlackUserLink::class);
    }

    /**
     * The reusable update and reply templates this user saved.
     *
     * @return HasMany<SavedReply, $this>
     */
    public function savedReplies(): HasMany
    {
        return $this->hasMany(SavedReply::class);
    }

    /**
     * The Update Feed filter combinations this user saved.
     *
     * @return HasMany<FeedSavedView, $this>
     */
    public function feedSavedViews(): HasMany
    {
        return $this->hasMany(FeedSavedView::class);
    }

    /**
     * Boards and items this user follows, see {@see FeedFollow}.
     *
     * @return HasMany<FeedFollow, $this>
     */
    public function feedFollows(): HasMany
    {
        return $this->hasMany(FeedFollow::class);
    }

    /**
     * The {@see Department} this user is assigned to, if any.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The devices/browsers this user has signed in from (Session history).
     *
     * @return HasMany<UserSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * Values for the account's custom profile fields (Administration > Profile fields).
     *
     * @return HasMany<UserProfileFieldValue, $this>
     */
    public function profileFieldValues(): HasMany
    {
        return $this->hasMany(UserProfileFieldValue::class);
    }

    /**
     * The in-app notifications delivered to this user.
     *
     * Overrides {@see Notifiable}'s polymorphic `notifiable_type`/`notifiable_id`
     * relation, this app never sends through Laravel's built-in notification
     * channels, it has its own `notifications` table keyed by a plain `user_id`.
     *
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Boards this user has muted notifications for.
     *
     * @return HasMany<BoardNotificationMute, $this>
     */
    public function boardNotificationMutes(): HasMany
    {
        return $this->hasMany(BoardNotificationMute::class);
    }

    /**
     * Items this user has muted notifications for.
     *
     * @return HasMany<BoardItemNotificationMute, $this>
     */
    public function boardItemNotificationMutes(): HasMany
    {
        return $this->hasMany(BoardItemNotificationMute::class);
    }

    /**
     * Send the password reset notification.
     *
     * Overrides the framework default (which fires the stock
     * `Illuminate\Auth\Notifications\ResetPassword` notification inline on
     * the request thread) so the email instead goes through the app's own
     * queued, rate limited `emails` pipeline, matching every other
     * transactional email in the app.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $frontend_url = rtrim((string) config('app.frontend_url'), '/');
        $expires_minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        $reset_url = "{$frontend_url}/reset-password/{$token}?".http_build_query([
            'email' => $this->getEmailForPasswordReset(),
        ]);

        SendEmailJob::dispatch(
            new PasswordResetMail($this, $token, $reset_url, $expires_minutes),
            $this->email,
        );
    }
}
