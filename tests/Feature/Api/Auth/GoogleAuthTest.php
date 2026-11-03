<?php

use App\Jobs\SendEmailJob;
use App\Mail\TwoFactorCodeMail;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * Fakes the Google profile returned to the callback, verified by default.
 *
 * @param  array<string, mixed>  $attributes
 */
function fakeGoogleUser(array $attributes = []): void
{
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-123',
        'name' => 'Jane Doe',
        'given_name' => 'Jane',
        'family_name' => 'Doe',
        'email' => 'jane@example.com',
        'email_verified' => true,
        ...$attributes,
    ]));
}

/**
 * Starts the flow through the redirect endpoint with a known state, then hits
 * the callback with it and returns the callback response.
 */
function googleCallback(string $redirect_query = '')
{
    Str::createRandomStringsUsing(fn () => 'test-state');
    test()->get('/api/auth/google/redirect'.$redirect_query)->assertRedirect();
    Str::createRandomStringsNormally();

    return test()->get('/api/auth/google/callback?state=test-state');
}

/**
 * Reads the query string of the frontend URL the callback redirected to.
 *
 * @return array<string, string>
 */
function redirectQuery($response): array
{
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query;
}

function exchangeGoogleLoginCode(string $login_code)
{
    return test()->postJson('/api/auth/google/exchange', ['login_code' => $login_code]);
}

test('redirect sends the user to google and stores the oauth state', function () {
    Socialite::fake('google');
    Str::createRandomStringsUsing(fn () => 'test-state');

    $this->get('/api/auth/google/redirect?remember=1&callback_url=/boards/42')->assertRedirect();

    expect(Cache::get('google_oauth_state:test-state'))->toBe([
        'remember' => true,
        'callback_url' => '/boards/42',
    ]);
});

test('redirect rejects a callback url that points to another site', function (string $callback_url) {
    Socialite::fake('google');

    $this->getJson('/api/auth/google/redirect?callback_url='.urlencode($callback_url))
        ->assertJsonValidationErrors('callback_url');
})->with(['https://evil.example.com', '//evil.example.com', '/\\evil.example.com']);

test('callback creates a new user with a personal team and the client role', function () {
    Bus::fake();
    fakeGoogleUser();

    $response = googleCallback();

    expect($response->headers->get('Location'))
        ->toStartWith(config('app.frontend_url').'/auth/google/callback?login_code=')
        ->not->toContain('token=');

    $user = User::where('email', 'jane@example.com')->firstOrFail();
    expect($user->google_id)->toBe('google-123');
    expect($user->first_name)->toBe('Jane');
    expect($user->hasRole('client'))->toBeTrue();
    expect($user->currentTeam)->not->toBeNull();

    Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipientEmail === 'jane@example.com'
        && $job->mailable instanceof WelcomeMail);
});

test('a new google user joins the workspaces they were invited to', function () {
    Bus::fake();
    fakeGoogleUser();

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'jane@example.com',
        'role' => 'viewer',
        'invited_by' => $owner->id,
    ]);

    googleCallback()->assertRedirect();

    $user = User::where('email', 'jane@example.com')->firstOrFail();
    expect($workspace->users()->whereKey($user->id)->exists())->toBeTrue();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('the login code is exchanged once for an access token and records the session', function () {
    Bus::fake();
    fakeGoogleUser();

    $login_code = redirectQuery(googleCallback('?callback_url=/boards/42'))['login_code'];

    exchangeGoogleLoginCode($login_code)
        ->assertOk()
        ->assertJsonStructure(['access_token', 'expires_in', 'session_expires_at', 'user'])
        ->assertJsonPath('user.email', 'jane@example.com')
        ->assertJsonPath('callback_url', '/boards/42');

    expect(UserSession::where('user_id', User::where('email', 'jane@example.com')->value('id'))->count())->toBe(1);

    exchangeGoogleLoginCode($login_code)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'google_auth_expired');
});

test('callback backfills google_id for an existing user matched by email', function () {
    $user = User::factory()->create(['email' => 'jane@example.com', 'google_id' => null]);
    fakeGoogleUser();

    googleCallback()->assertRedirect();

    expect($user->fresh()->google_id)->toBe('google-123');
});

test('callback signs in a user already linked by google_id', function () {
    $user = User::factory()->create(['google_id' => 'google-123']);
    fakeGoogleUser(['email' => $user->email]);

    $login_code = redirectQuery(googleCallback())['login_code'];

    exchangeGoogleLoginCode($login_code)->assertOk()->assertJsonPath('user.id', $user->id);
});

test('callback refuses to relink an email that belongs to another google account', function () {
    $user = User::factory()->create(['email' => 'jane@example.com', 'google_id' => 'google-original']);
    fakeGoogleUser(['id' => 'google-other']);

    expect(redirectQuery(googleCallback()))->toBe(['error' => 'google_account_mismatch']);
    expect($user->fresh()->google_id)->toBe('google-original');
});

test('callback refuses a google email that is not verified', function () {
    $user = User::factory()->create(['email' => 'jane@example.com', 'google_id' => null]);
    fakeGoogleUser(['email_verified' => false]);

    expect(redirectQuery(googleCallback()))->toBe(['error' => 'google_email_unverified']);
    expect($user->fresh()->google_id)->toBeNull();
});

test('callback redirects with an error for a disabled account', function () {
    $user = User::factory()->create(['google_id' => 'google-123', 'is_active' => false]);
    fakeGoogleUser(['email' => $user->email]);

    expect(redirectQuery(googleCallback()))->toBe(['error' => 'account_disabled']);
});

test('callback rejects a missing or unknown oauth state', function (string $query) {
    fakeGoogleUser();

    $response = $this->get('/api/auth/google/callback'.$query);

    expect(redirectQuery($response))->toBe(['error' => 'google_auth_expired']);
    expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
})->with(['', '?state=forged-state']);

test('the oauth state can only be used once', function () {
    fakeGoogleUser();

    googleCallback()->assertRedirect();

    expect(redirectQuery($this->get('/api/auth/google/callback?state=test-state')))
        ->toBe(['error' => 'google_auth_expired']);
});

test('callback reports a cancelled google consent screen', function () {
    fakeGoogleUser();

    $response = $this->get('/api/auth/google/callback?error=access_denied&state=test-state');

    expect(redirectQuery($response))->toBe(['error' => 'google_auth_cancelled']);
});

test('a google sign in still has to pass the two factor challenge', function () {
    Bus::fake();
    $user = User::factory()->withTwoFactor()->create(['google_id' => 'google-123']);
    fakeGoogleUser(['email' => $user->email]);

    $login_code = redirectQuery(googleCallback('?remember=1'))['login_code'];

    $response = exchangeGoogleLoginCode($login_code)
        ->assertOk()
        ->assertJsonPath('requires_two_factor', true)
        ->assertJsonMissingPath('access_token');

    $two_factor_token = $response->json('two_factor_token');

    expect(Cache::get("two_factor_pending:{$two_factor_token}"))->toBe($user->id);
    expect(Cache::get("two_factor_remember:{$two_factor_token}"))->toBeTrue();
    Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->mailable instanceof TwoFactorCodeMail);
});

test('a login code is refused once the account has been disabled', function () {
    $user = User::factory()->create(['google_id' => 'google-123']);
    fakeGoogleUser(['email' => $user->email]);

    $login_code = redirectQuery(googleCallback())['login_code'];
    $user->update(['is_active' => false]);

    exchangeGoogleLoginCode($login_code)->assertForbidden()->assertJsonPath('code', 'account_disabled');
});
