<?php

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    // Joining by link for a brand-new email assigns the global "client" role,
    // same as register() and accepting an email invitation.
    $this->seed(RolePermissionSeeder::class);
});

test('a workspace owner can view its invite link', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/invite-link");

    $response->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('role', 'member')
        ->assertJsonStructure(['url', 'role', 'role_label', 'enabled']);

    expect($response->json('url'))->toContain($workspace->invite_code);
});

test('a member cannot view or manage the invite link', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/invite-link")
        ->assertStatus(403);

    $this->actingAs($member, 'api')
        ->patchJson("/api/workspaces/{$workspace->slug}/invite-link", ['enabled' => false])
        ->assertStatus(403);
});

test('a workspace owner can disable link invites and change the granted role', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $response = $this->actingAs($owner, 'api')
        ->patchJson("/api/workspaces/{$workspace->slug}/invite-link", [
            'enabled' => false,
            'role' => 'viewer',
        ]);

    $response->assertOk()
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('role', 'viewer');

    expect($workspace->fresh()->invite_enabled)->toBeFalse();
});

test('a workspace owner can regenerate the invite link, invalidating the old one', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $old_code = $workspace->invite_code;

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invite-link/regenerate");

    $response->assertOk();

    $workspace->refresh();
    expect($workspace->invite_code)->not->toBe($old_code);
    expect($workspace->invite_generated_by)->toBe($owner->id);

    $this->getJson("/api/auth/workspaces/join/{$old_code}")->assertNotFound();
});

test('an unknown email joining by link creates an account and joins with the link role', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->update(['invite_role' => 'viewer', 'invite_generated_by' => $owner->id]);

    $preview = $this->getJson("/api/auth/workspaces/join/{$workspace->invite_code}");
    $preview->assertOk()
        ->assertJsonPath('role', 'viewer')
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('workspace.name', $workspace->name);

    $response = $this->postJson("/api/auth/workspaces/join/{$workspace->invite_code}", [
        'email' => 'newperson@example.com',
        'first_name' => 'New',
        'last_name' => 'Person',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertOk()->assertJsonStructure(['access_token', 'user']);

    $user = User::where('email', 'newperson@example.com')->firstOrFail();
    $membership = $workspace->fresh()->users()->where('user_id', $user->id)->first();

    expect($membership->pivot->role)->toBe('viewer');
    expect($membership->pivot->invited_by)->toBe($owner->id);
});

test('an existing user joining by link must confirm their password', function () {
    $owner = User::factory()->create();
    $existing_user = User::factory()->create(['email' => 'existing@example.com', 'password' => 'correct-password']);
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $this->postJson("/api/auth/workspaces/join/{$workspace->invite_code}", [
        'email' => 'existing@example.com',
        'password' => 'wrong-password',
    ])->assertUnprocessable();

    $response = $this->postJson("/api/auth/workspaces/join/{$workspace->invite_code}", [
        'email' => 'existing@example.com',
        'password' => 'correct-password',
    ]);
    $response->assertOk();

    expect($workspace->fresh()->users()->where('user_id', $existing_user->id)->first()->pivot->role)->toBe('member');
});

test('joining a disabled invite link is rejected', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->update(['invite_enabled' => false]);

    $response = $this->postJson("/api/auth/workspaces/join/{$workspace->invite_code}", [
        'email' => 'newperson@example.com',
        'first_name' => 'New',
        'last_name' => 'Person',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertUnprocessable();
    expect(User::where('email', 'newperson@example.com')->exists())->toBeFalse();
});

test('an unknown invite code returns a 404', function () {
    $this->getJson('/api/auth/workspaces/join/does-not-exist')->assertNotFound();
});
