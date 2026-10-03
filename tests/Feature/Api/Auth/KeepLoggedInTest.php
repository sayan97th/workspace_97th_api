<?php

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Models\UserSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Token;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->freezeSecond();
});

/**
 * The JWT manager is an app singleton that caches the last token it saw, so it has
 * to be dropped before each request that switches tokens (see UserSessionControllerTest).
 */
function forgetJwtInstances(): void
{
    app()->forgetInstance('tymon.jwt');
    app()->forgetInstance('tymon.jwt.auth');
    Auth::forgetGuards();
}

function signInForSession(User $user, ?bool $remember = null): array
{
    forgetJwtInstances();

    $payload = ['email' => $user->email, 'password' => 'password'];

    if ($remember !== null) {
        $payload['remember'] = $remember;
    }

    return test()->postJson('/api/auth/login', $payload)->assertOk()->json();
}

function refreshSession(string $token)
{
    forgetJwtInstances();

    return test()->withHeader('Authorization', "Bearer {$token}")->postJson('/api/auth/refresh');
}

function sessionClaim(string $token, string $claim): mixed
{
    forgetJwtInstances();

    return JWTAuth::manager()->setRefreshFlow()->decode(new Token($token))->get($claim);
}

test('a normal sign in lasts one day', function () {
    $user = User::factory()->create();

    $data = signInForSession($user);

    expect($data['remember'])->toBeFalse();
    expect(Carbon::parse($data['session_expires_at'])->equalTo(now()->addDay()))->toBeTrue();
    expect(UserSession::where('user_id', $user->id)->first()->expires_at->equalTo(now()->addDay()))->toBeTrue();
});

test('keep me logged in makes the session last thirty days', function () {
    $user = User::factory()->create();

    $data = signInForSession($user, remember: true);

    expect($data['remember'])->toBeTrue();
    expect(Carbon::parse($data['session_expires_at'])->equalTo(now()->addDays(30)))->toBeTrue();
});

test('an expired access token can still be refreshed while its session is alive', function () {
    $user = User::factory()->create();
    $data = signInForSession($user);

    $this->travel(3)->hours();

    refreshSession($data['access_token'])
        ->assertOk()
        ->assertJsonStructure(['access_token', 'expires_in', 'session_expires_at'])
        ->assertJsonPath('session_expires_at', $data['session_expires_at']);
});

test('a normal session can no longer be refreshed after one day', function () {
    $user = User::factory()->create();
    $data = signInForSession($user);

    $this->travel(25)->hours();

    refreshSession($data['access_token'])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'session_expired');
});

test('a remembered session survives past one day and keeps its original end date', function () {
    $user = User::factory()->create();
    $data = signInForSession($user, remember: true);

    $this->travel(5)->days();

    $refreshed = refreshSession($data['access_token'])->assertOk()->json();

    expect($refreshed['remember'])->toBeTrue();
    expect($refreshed['session_expires_at'])->toBe($data['session_expires_at']);
    expect(sessionClaim($refreshed['access_token'], 'remember'))->toBeTrue();

    $this->travel(26)->days();

    refreshSession($refreshed['access_token'])->assertUnauthorized();
});

test('a refreshed access token never outlives its session', function () {
    $user = User::factory()->create();
    $data = signInForSession($user);

    $this->travelTo(Carbon::parse($data['session_expires_at'])->subMinutes(20));

    $refreshed = refreshSession($data['access_token'])->assertOk()->json();

    expect($refreshed['expires_in'])->toBe(20 * 60);
});

test('a session logged out from session history cannot be refreshed', function () {
    $user = User::factory()->create();
    $data = signInForSession($user, remember: true);

    UserSession::where('user_id', $user->id)->update(['revoked_at' => now()]);

    refreshSession($data['access_token'])->assertUnauthorized();
});

test('a disabled account cannot refresh its session', function () {
    $user = User::factory()->create();
    $data = signInForSession($user, remember: true);

    $user->update(['is_active' => false]);

    refreshSession($data['access_token'])->assertUnauthorized();
});

test('refresh rejects a missing or forged token', function () {
    forgetJwtInstances();
    $this->postJson('/api/auth/refresh')->assertUnauthorized();

    refreshSession('not-a-real-token')->assertUnauthorized();
});

test('keep me logged in survives the two factor challenge', function () {
    Bus::fake();

    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $login = signInForSession($user, remember: true);
    expect($login['requires_two_factor'])->toBeTrue();
    expect(UserSession::where('user_id', $user->id)->exists())->toBeFalse();

    $email_code = null;
    Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use (&$email_code) {
        $email_code = $job->mailable->code;

        return true;
    });

    forgetJwtInstances();
    $this->postJson('/api/auth/two-factor-challenge', [
        'two_factor_token' => $login['two_factor_token'],
        'code' => $email_code,
    ])->assertOk()->assertJsonPath('remember', true);
});

test('google sign in carries keep me logged in through the oauth state', function () {
    Bus::fake();

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-remember',
        'name' => 'Jane Doe',
        'email' => 'jane.remember@example.com',
    ]));

    $this->get('/api/auth/google/redirect?remember=1')->assertRedirect();

    Cache::put('google_oauth_remember:test-state', true, now()->addMinutes(10));

    $location = $this->get('/api/auth/google/callback?state=test-state')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(Carbon::parse($query['session_expires_at'])->equalTo(now()->addDays(30)))->toBeTrue();
    expect(Cache::has('google_oauth_remember:test-state'))->toBeFalse();
});

test('google sign in without keep me logged in lasts one day', function () {
    Bus::fake();

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-normal',
        'name' => 'John Doe',
        'email' => 'john.normal@example.com',
    ]));

    $location = $this->get('/api/auth/google/callback')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(Carbon::parse($query['session_expires_at'])->equalTo(now()->addDay()))->toBeTrue();
});
