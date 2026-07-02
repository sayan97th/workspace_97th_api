<?php

use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

test('two factor status is disabled for a fresh user', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->getJson('/api/auth/two-factor')
        ->assertOk()
        ->assertJsonPath('enabled', false);
});

test('setup generates a secret and qr code without enabling two factor', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->postJson('/api/auth/two-factor')
        ->assertOk()
        ->assertJsonStructure(['secret', 'svg', 'url']);

    expect($response->json('secret'))->not->toBeEmpty();
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('confirm rejects an invalid code', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'api')->postJson('/api/auth/two-factor');

    $this->actingAs($user, 'api')->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

test('confirm enables two factor and returns recovery codes for a valid code', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'api')->postJson('/api/auth/two-factor');

    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $response = $this->actingAs($user, 'api')->postJson('/api/auth/two-factor/confirm', ['code' => $code])
        ->assertOk();

    expect($response->json('recovery_codes'))->toHaveCount(8);
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('disable requires the correct current password', function () {
    $user = User::factory()->withTwoFactor()->create(['password' => 'password123']);

    $this->actingAs($user, 'api')->deleteJson('/api/auth/two-factor', ['password' => 'wrong-password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    $this->actingAs($user, 'api')->deleteJson('/api/auth/two-factor', ['password' => 'password123'])
        ->assertOk();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('recovery codes can be regenerated with the correct password', function () {
    $user = User::factory()->withTwoFactor()->create(['password' => 'password123']);
    $original_codes = $user->recoveryCodes();

    $response = $this->actingAs($user, 'api')->postJson('/api/auth/two-factor/recovery-codes', [
        'password' => 'password123',
    ])->assertOk();

    expect($response->json('recovery_codes'))->not->toEqual($original_codes);
});

test('login for a two factor enabled user requires a challenge instead of returning a token', function () {
    $user = User::factory()->withTwoFactor()->create(['password' => 'password123']);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])
        ->assertOk()
        ->assertJsonPath('requires_two_factor', true)
        ->assertJsonStructure(['two_factor_token'])
        ->assertJsonMissing(['access_token']);
});

test('two factor challenge issues a token for a valid code', function () {
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create([
        'password' => 'password123',
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $login = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertOk();

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->postJson('/api/auth/two-factor-challenge', [
        'two_factor_token' => $login->json('two_factor_token'),
        'code' => $code,
    ])->assertOk()->assertJsonStructure(['access_token']);
});

test('two factor challenge issues a token for a valid recovery code and consumes it', function () {
    $user = User::factory()->withTwoFactor()->create(['password' => 'password123']);

    $login = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertOk();

    $this->postJson('/api/auth/two-factor-challenge', [
        'two_factor_token' => $login->json('two_factor_token'),
        'recovery_code' => 'recovery-code-1',
    ])->assertOk()->assertJsonStructure(['access_token']);

    expect($user->fresh()->recoveryCodes())->not->toContain('recovery-code-1');
});

test('two factor challenge rejects an invalid or expired token', function () {
    $this->postJson('/api/auth/two-factor-challenge', [
        'two_factor_token' => 'not-a-real-token',
        'code' => '123456',
    ])->assertStatus(422);
});
