<?php

use App\Models\ImpersonationSession;
use App\Models\User;
use App\Models\UserSession;
use Database\Seeders\RolePermissionSeeder;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Token;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function makeUserWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Every real request boots a fresh app, but within one test the guard and the JWT singleton
 * keep the last parsed token, so reset both before switching identities.
 */
function resetAuthState(): void
{
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken();
    JWTAuth::unsetToken();
}

function bearerFor(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

test('a super admin can impersonate a client and gets a short lived token', function () {
    $admin = makeUserWithRole('super_admin');
    $client = makeUserWithRole('client');

    $response = $this->withHeaders(bearerFor($admin))
        ->postJson("/api/admin/users/{$client->id}/impersonate");

    $response->assertOk()
        ->assertJsonPath('user.id', $client->id)
        ->assertJsonPath('impersonation.admin.id', $admin->id)
        ->assertJsonPath('expires_in', 30 * 60)
        ->assertJsonPath('remember', false);

    $payload = JWTAuth::setToken($response->json('access_token'))->getPayload();
    $session = ImpersonationSession::first();

    expect((int) $payload->get('sub'))->toBe($client->id)
        ->and($payload->get('impersonator_id'))->toBe($admin->id)
        ->and($payload->get('impersonation_session_id'))->toBe($session->id)
        ->and($payload->get('session_expires_at'))->toBeLessThanOrEqual(now()->addMinutes(30)->timestamp)
        ->and($session->impersonation_jti)->toBe($payload->get('jti'))
        ->and($session->ended_at)->toBeNull()
        ->and(UserSession::where('jti', $payload->get('jti'))->where('user_id', $client->id)->exists())->toBeTrue();
});

test('stopping an impersonation closes the session and revokes the token', function () {
    $admin = makeUserWithRole('super_admin');
    $client = makeUserWithRole('client');

    $token = $this->withHeaders(bearerFor($admin))
        ->postJson("/api/admin/users/{$client->id}/impersonate")
        ->json('access_token');

    resetAuthState();

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/impersonation/stop')
        ->assertOk();

    $session = ImpersonationSession::first();
    $jti = JWTAuth::manager()->decode(new Token($token), false)->get('jti');

    expect($session->ended_at)->not->toBeNull()
        ->and($session->ended_reason)->toBe('manual')
        ->and(UserSession::where('jti', $jti)->first()->revoked_at)->not->toBeNull();
});

test('an impersonation token cannot be refreshed', function () {
    $admin = makeUserWithRole('super_admin');
    $client = makeUserWithRole('client');

    $token = $this->withHeaders(bearerFor($admin))
        ->postJson("/api/admin/users/{$client->id}/impersonate")
        ->json('access_token');

    resetAuthState();

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/auth/refresh')
        ->assertStatus(422);
});

test('a plain admin cannot impersonate anyone', function () {
    $admin = makeUserWithRole('admin');
    $client = makeUserWithRole('client');

    $this->withHeaders(bearerFor($admin))
        ->postJson("/api/admin/users/{$client->id}/impersonate")
        ->assertForbidden();

    expect(ImpersonationSession::count())->toBe(0);
});

test('staff and clients cannot impersonate anyone', function (string $role) {
    $actor = makeUserWithRole($role);
    $client = makeUserWithRole('client');

    $this->withHeaders(bearerFor($actor))
        ->postJson("/api/admin/users/{$client->id}/impersonate")
        ->assertForbidden();

    expect(ImpersonationSession::count())->toBe(0);
})->with(['staff', 'client']);

test('a super admin can impersonate an admin', function () {
    $super_admin = makeUserWithRole('super_admin');
    $admin = makeUserWithRole('admin');

    $this->withHeaders(bearerFor($super_admin))
        ->postJson("/api/admin/users/{$admin->id}/impersonate")
        ->assertOk()
        ->assertJsonPath('user.id', $admin->id);
});

test('nobody can impersonate a super admin', function () {
    $super_admin = makeUserWithRole('super_admin');
    $other_super_admin = makeUserWithRole('super_admin');

    $this->withHeaders(bearerFor($super_admin))
        ->postJson("/api/admin/users/{$other_super_admin->id}/impersonate")
        ->assertForbidden();
});

test('starting a new impersonation closes the sessions this admin left open', function () {
    $admin = makeUserWithRole('super_admin');
    $client = makeUserWithRole('client');
    $abandoned_target = makeUserWithRole('client');

    $abandoned_session = ImpersonationSession::create([
        'admin_id' => $admin->id,
        'target_user_id' => $abandoned_target->id,
        'impersonation_jti' => 'abandoned-jti',
        'started_at' => now()->subMinutes(5),
    ]);
    $expired_session = ImpersonationSession::create([
        'admin_id' => $admin->id,
        'target_user_id' => $abandoned_target->id,
        'impersonation_jti' => 'expired-jti',
        'started_at' => now()->subHours(2),
    ]);
    UserSession::create([
        'user_id' => $abandoned_target->id,
        'jti' => 'abandoned-jti',
        'last_used_at' => now(),
        'expires_at' => now()->addMinutes(25),
    ]);

    $this->withHeaders(bearerFor($admin))
        ->postJson("/api/admin/users/{$client->id}/impersonate")
        ->assertOk();

    expect($abandoned_session->fresh()->ended_reason)->toBe('superseded')
        ->and($expired_session->fresh()->ended_reason)->toBe('expired')
        ->and($expired_session->fresh()->ended_at->equalTo($expired_session->started_at->copy()->addMinutes(30)))->toBeTrue()
        ->and(UserSession::where('jti', 'abandoned-jti')->first()->revoked_at)->not->toBeNull()
        ->and(ImpersonationSession::whereNull('ended_at')->count())->toBe(1);
});
