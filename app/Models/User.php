<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasRoles;
use App\Concerns\HasTeams;
use App\Jobs\SendEmailJob;
use App\Mail\PasswordResetMail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string|null $timezone
 * @property string|null $profile_photo_path
 * @property bool $is_active
 * @property string|null $working_status
 * @property string|null $working_status_dates
 * @property bool $disable_notifications_while_away
 * @property bool $hide_online_status
 * @property array<string, bool>|null $notification_preferences
 * @property bool $desktop_notifications_enabled
 * @property string $language
 * @property string $time_format
 * @property string $date_format
 * @property string $first_day_of_week
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $full_name
 * @property-read string|null $profile_photo_url
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Role> $roles
 * @property-read Collection<int, Workspace> $workspaces
 * @property-read Collection<int, AccountTeam> $accountTeams
 * @property-read Collection<int, UserSession> $sessions
 */
#[Fillable([
    'first_name', 'last_name', 'email', 'google_id', 'password', 'current_team_id', 'phone', 'job_title', 'timezone', 'profile_photo_path', 'is_active',
    'working_status', 'working_status_dates', 'disable_notifications_while_away', 'hide_online_status',
    'notification_preferences', 'desktop_notifications_enabled',
    'language', 'time_format', 'date_format', 'first_day_of_week',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
#[Appends(['full_name', 'profile_photo_url'])]
class User extends Authenticatable implements JWTSubject, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasTeams, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

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
            'desktop_notifications_enabled' => 'boolean',
        ];
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
                ? Storage::disk('public')->url($this->profile_photo_path)
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
     * The devices/browsers this user has signed in from (Session history).
     *
     * @return HasMany<UserSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
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
