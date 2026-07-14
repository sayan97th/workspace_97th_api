<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

test('creates an admin user with a personal team and the admin role', function () {
    $this->artisan('admin:create-admin')
        ->expectsQuestion('First name', 'Jane')
        ->expectsQuestion('Last name', 'Doe')
        ->expectsQuestion('Email address', 'jane.doe@example.com')
        ->expectsQuestion('Password', 'super-secret-password')
        ->expectsQuestion('Confirm password', 'super-secret-password')
        ->assertExitCode(0);

    $user = User::where('email', 'jane.doe@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->first_name)->toBe('Jane')
        ->and($user->last_name)->toBe('Doe')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasRole('admin'))->toBeTrue()
        ->and($user->currentTeam)->not->toBeNull()
        ->and($user->currentTeam->is_personal)->toBeTrue();
});

test('seeds the admin role automatically when it does not exist yet', function () {
    expect(Role::where('name', 'admin')->exists())->toBeFalse();

    $this->artisan('admin:create-admin')
        ->expectsQuestion('First name', 'Jane')
        ->expectsQuestion('Last name', 'Doe')
        ->expectsQuestion('Email address', 'jane.doe@example.com')
        ->expectsQuestion('Password', 'super-secret-password')
        ->expectsQuestion('Confirm password', 'super-secret-password')
        ->assertExitCode(0);

    expect(Role::where('name', 'admin')->exists())->toBeTrue();
    expect(User::where('email', 'jane.doe@example.com')->first()->hasRole('admin'))->toBeTrue();
});

test('fails when the email already exists', function () {
    (new RolePermissionSeeder)->run();
    User::factory()->create(['email' => 'jane.doe@example.com']);

    $this->artisan('admin:create-admin')
        ->expectsQuestion('First name', 'Jane')
        ->expectsQuestion('Last name', 'Doe')
        ->expectsQuestion('Email address', 'jane.doe@example.com')
        ->assertExitCode(1);
});

test('fails when the passwords do not match', function () {
    (new RolePermissionSeeder)->run();

    $this->artisan('admin:create-admin')
        ->expectsQuestion('First name', 'Jane')
        ->expectsQuestion('Last name', 'Doe')
        ->expectsQuestion('Email address', 'jane.doe@example.com')
        ->expectsQuestion('Password', 'super-secret-password')
        ->expectsQuestion('Confirm password', 'different-password')
        ->assertExitCode(1);

    expect(User::where('email', 'jane.doe@example.com')->exists())->toBeFalse();
});
