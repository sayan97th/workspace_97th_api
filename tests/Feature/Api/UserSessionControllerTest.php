<?php

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * php-open-source-saver/jwt-auth binds its JWT manager as an app singleton and caches
 * the last token explicitly set on it (via login/attempt/refresh/setToken). That's a
 * non-issue in real traffic (one process per request), but within a single Pest test
 * that logs in as multiple "devices" and switches Bearer tokens between requests, the
 * cached token bleeds across requests unless the guard/manager instances are dropped
 * first. Forgetting these container instances forces a fresh parse of whichever token
 * is actually on the next request.
 */
function resetJwtState(): void
{
    app()->forgetInstance('tymon.jwt');
    app()->forgetInstance('tymon.jwt.auth');
    Auth::forgetGuards();
}

function withToken(string $token): TestResponse|TestCase
{
    resetJwtState();

    return test()->withHeader('Authorization', "Bearer {$token}");
}

function loginAndGetToken(User $user): string
{
    resetJwtState();

    $response = test()->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    return $response->json('access_token');
}

function decryptJti(string $token): string
{
    resetJwtState();

    return JWTAuth::setToken($token)->getPayload()->get('jti');
}

test('logging in creates a session row', function () {
    $user = User::factory()->create();

    $token = loginAndGetToken($user);

    expect(UserSession::where('user_id', $user->id)->count())->toBe(1);

    $session = UserSession::where('user_id', $user->id)->first();
    expect($session->revoked_at)->toBeNull();
    expect($session->device_label)->not->toBeNull();

    // sanity check the token actually authenticates against /me
    withToken($token)->getJson('/api/auth/me')->assertOk();
});

test('refreshing a token rotates the same session row instead of creating a new one', function () {
    $user = User::factory()->create();
    $token = loginAndGetToken($user);
    $original_session_id = UserSession::where('user_id', $user->id)->first()->id;

    withToken($token)->postJson('/api/auth/refresh')->assertOk();

    expect(UserSession::where('user_id', $user->id)->count())->toBe(1);
    expect(UserSession::find($original_session_id))->not->toBeNull();
});

test('a user can list their sessions with the current device flagged', function () {
    $user = User::factory()->create();
    $token = loginAndGetToken($user);

    $response = withToken($token)->getJson('/api/profile/sessions');

    $response->assertOk();
    $rows = $response->json('data');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['is_current_device'])->toBeTrue();
    expect($rows[0]['can_logout'])->toBeFalse();
});

test('a user can log out a different session, and that session is then rejected', function () {
    $user = User::factory()->create();
    $token_a = loginAndGetToken($user);
    $token_b = loginAndGetToken($user);

    expect(UserSession::where('user_id', $user->id)->count())->toBe(2);

    $session_b = UserSession::where('jti', decryptJti($token_b))->first();

    // From device A, log out device B's session.
    withToken($token_a)->deleteJson("/api/profile/sessions/{$session_b->id}")->assertOk();

    expect($session_b->fresh()->revoked_at)->not->toBeNull();

    // Device B's token is now rejected.
    withToken($token_b)->getJson('/api/auth/me')->assertUnauthorized();

    // Device A is unaffected.
    withToken($token_a)->getJson('/api/auth/me')->assertOk();
});

test('a user cannot log out their own current session via the sessions endpoint', function () {
    $user = User::factory()->create();
    $token = loginAndGetToken($user);
    $session = UserSession::where('user_id', $user->id)->first();

    withToken($token)->deleteJson("/api/profile/sessions/{$session->id}")->assertStatus(422);
});

test('a user cannot log out another user\'s session', function () {
    $user = User::factory()->create();
    $other_user = User::factory()->create();
    $token = loginAndGetToken($user);
    loginAndGetToken($other_user);
    $other_session = UserSession::where('user_id', $other_user->id)->first();

    withToken($token)->deleteJson("/api/profile/sessions/{$other_session->id}")->assertStatus(404);
});
