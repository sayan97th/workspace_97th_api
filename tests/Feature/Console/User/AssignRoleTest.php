<?php

use App\Models\User;
use App\Models\Workspace;
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
        ->expectsChoice('Select a role to assign', 'staff (system role)', [
            'super_admin (system role)', 'admin (system role)', 'staff (system role)', 'client (system role)',
            'owner (workspace role)', 'member (workspace role)', 'viewer (workspace role)',
        ])
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

test('assigns a workspace role to a user who is not yet a member of the workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $this->artisan('admin:assign-role', [
        'email' => $user->email,
        '--role' => 'viewer',
        '--workspace' => $workspace->slug,
    ])->assertExitCode(0);

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'viewer',
    ]);
});

test('changes the workspace role of an existing member', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'member']);

    $this->artisan('admin:assign-role', [
        'email' => $user->email,
        '--role' => 'viewer',
        '--workspace' => $workspace->slug,
    ])->assertExitCode(0);

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'viewer',
    ]);
});

test('resolves the workspace by id as well as by slug', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'member']);

    $this->artisan('admin:assign-role', [
        'email' => $user->email,
        '--role' => 'viewer',
        '--workspace' => $workspace->id,
    ])->assertExitCode(0);

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'viewer',
    ]);
});

test('auto-selects the workspace when the user belongs to exactly one and none is passed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'member']);

    $this->artisan('admin:assign-role', ['email' => $user->email, '--role' => 'viewer'])
        ->assertExitCode(0);

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'viewer',
    ]);
});

test('fails to assign a workspace role when the user belongs to no workspace and none is passed', function () {
    $user = User::factory()->create();

    $this->artisan('admin:assign-role', ['email' => $user->email, '--role' => 'viewer'])
        ->assertExitCode(1);
});

test('fails when the given workspace does not exist', function () {
    $user = User::factory()->create();

    $this->artisan('admin:assign-role', [
        'email' => $user->email,
        '--role' => 'viewer',
        '--workspace' => 'not-a-workspace',
    ])->assertExitCode(1);
});

test('blocks demoting the sole remaining owner of a workspace', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['created_by' => $owner->id]);
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $this->artisan('admin:assign-role', [
        'email' => $owner->email,
        '--role' => 'member',
        '--workspace' => $workspace->slug,
    ])->assertExitCode(1);

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'role' => 'owner',
    ]);
});

test('allows demoting an owner when another owner remains', function () {
    $owner = User::factory()->create();
    $other_owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['created_by' => $owner->id]);
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($other_owner->id, ['role' => 'owner']);

    $this->artisan('admin:assign-role', [
        'email' => $owner->email,
        '--role' => 'member',
        '--workspace' => $workspace->slug,
    ])->assertExitCode(0);

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'role' => 'member',
    ]);
});
