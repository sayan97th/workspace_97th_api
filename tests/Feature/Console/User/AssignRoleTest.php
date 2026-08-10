<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

test('assigns a role to a user when email and role are passed as arguments', function () {
    $user = User::factory()->create();

    $this->artisan('admin:assign-role', ['email' => $user->email, '--role' => 'staff'])
        ->assertExitCode(0);

    expect($user->fresh()->hasRole('staff'))->toBeTrue();
});

test('adds the role without removing existing roles by default', function () {
    $user = User::factory()->create();
    $user->assignRole('client');

    $this->artisan('admin:assign-role', ['email' => $user->email, '--role' => 'staff'])
        ->assertExitCode(0);

    $user->refresh();
    expect($user->hasRole('client'))->toBeTrue()
        ->and($user->hasRole('staff'))->toBeTrue();
});

test('replaces existing roles when the sync option is used', function () {
    $user = User::factory()->create();
    $user->assignRole('client');

    $this->artisan('admin:assign-role', ['email' => $user->email, '--role' => 'staff', '--sync' => true])
        ->assertExitCode(0);

    $user->refresh();
    expect($user->hasRole('client'))->toBeFalse()
        ->and($user->hasRole('staff'))->toBeTrue();
});

test('prompts for the email and role when not provided as arguments', function () {
    $user = User::factory()->create();

    $this->artisan('admin:assign-role')
        ->expectsQuestion('Email address of the user', $user->email)
        ->expectsChoice('Select a role to assign', 'staff', ['super_admin', 'admin', 'staff', 'client'])
        ->assertExitCode(0);

    expect($user->fresh()->hasRole('staff'))->toBeTrue();
});

test('fails when no user exists with the given email', function () {
    $this->artisan('admin:assign-role', ['email' => 'missing@example.com', '--role' => 'staff'])
        ->assertExitCode(1);
});

test('fails when the given role does not exist', function () {
    $user = User::factory()->create();

    $this->artisan('admin:assign-role', ['email' => $user->email, '--role' => 'not-a-role'])
        ->assertExitCode(1);

    expect($user->fresh()->roles)->toHaveCount(0);
});
