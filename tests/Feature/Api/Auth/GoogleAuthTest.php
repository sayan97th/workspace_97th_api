<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('redirect sends the user to google', function () {
    Socialite::fake('google');

    $this->get('/api/auth/google/redirect')->assertRedirect();
});

test('callback creates a new user with a personal team and the client role', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-123',
        'name' => 'Jane Doe',
        'given_name' => 'Jane',
        'family_name' => 'Doe',
        'email' => 'jane@example.com',
    ]));

    $response = $this->get('/api/auth/google/callback');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain(config('app.frontend_url').'/auth/google/callback?token=');

    $user = User::where('email', 'jane@example.com')->firstOrFail();
    expect($user->google_id)->toBe('google-123');
    expect($user->hasRole('client'))->toBeTrue();
    expect($user->currentTeam)->not->toBeNull();
});

test('callback backfills google_id for an existing user matched by email', function () {
    $user = User::factory()->create(['email' => 'jane@example.com', 'google_id' => null]);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-123',
        'name' => $user->full_name,
        'given_name' => $user->first_name,
        'family_name' => $user->last_name,
        'email' => 'jane@example.com',
    ]));

    $this->get('/api/auth/google/callback')->assertRedirect();

    expect($user->fresh()->google_id)->toBe('google-123');
});

test('callback issues a token directly for a user already linked by google_id', function () {
    $user = User::factory()->create(['google_id' => 'google-123']);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-123',
        'name' => $user->full_name,
        'given_name' => $user->first_name,
        'family_name' => $user->last_name,
        'email' => $user->email,
    ]));

    $response = $this->get('/api/auth/google/callback');

    expect($response->headers->get('Location'))->toContain('token=');
});

test('callback redirects with an error for a disabled account', function () {
    $user = User::factory()->create(['google_id' => 'google-123', 'is_active' => false]);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-123',
        'name' => $user->full_name,
        'given_name' => $user->first_name,
        'family_name' => $user->last_name,
        'email' => $user->email,
    ]));

    $response = $this->get('/api/auth/google/callback');

    expect($response->headers->get('Location'))->toContain('error=account_disabled');
});
