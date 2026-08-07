<?php

use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    // Accepting an invitation for a brand-new email assigns the global
    // "client" role, same as register() — needs the role catalog seeded.
    $this->seed(RolePermissionSeeder::class);
});

test('a workspace owner can invite members by email', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invitations", [
            'emails' => ['invited@example.com'],
            'role' => 'viewer',
            'message' => 'Welcome aboard!',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.0.email', 'invited@example.com')
        ->assertJsonPath('data.0.role', 'viewer');

    $this->assertDatabaseHas('workspace_invitations', [
        'workspace_id' => $workspace->id,
        'email' => 'invited@example.com',
        'role' => 'viewer',
    ]);

    Mail::assertSent(WorkspaceInvitationMail::class);
});

test('members cannot invite people to a workspace', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);

    $response = $this->actingAs($member, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invitations", [
            'emails' => ['invited@example.com'],
            'role' => 'member',
        ]);

    $response->assertStatus(403);
});

test('existing workspace members are skipped instead of re-invited', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $existing_member = User::factory()->create(['email' => 'member@example.com']);
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($existing_member->id, ['role' => 'member']);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invitations", [
            'emails' => ['member@example.com'],
            'role' => 'member',
        ]);

    $response->assertCreated()
        ->assertJsonPath('skipped.0.email', 'member@example.com')
        ->assertJsonPath('skipped.0.reason', 'already_member')
        ->assertJsonCount(0, 'data');

    Mail::assertNothingSent();
});

test('an invalid role is rejected', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invitations", [
            'emails' => ['invited@example.com'],
            'role' => 'nonmember',
        ]);

    $response->assertJsonValidationErrors('role');
});

test('an unknown email accepting an invitation creates an account and joins with the invited role', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'newperson@example.com',
        'role' => 'viewer',
        'invited_by' => $owner->id,
    ]);

    $preview = $this->getJson("/api/auth/invitations/{$invitation->code}");
    $preview->assertOk()
        ->assertJsonPath('account_exists', false)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('role', 'viewer');

    $response = $this->postJson("/api/auth/invitations/{$invitation->code}/accept", [
        'first_name' => 'New',
        'last_name' => 'Person',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertOk()->assertJsonStructure(['access_token', 'user']);

    $user = User::where('email', 'newperson@example.com')->first();
    expect($user)->not->toBeNull();
    expect($workspace->fresh()->users()->where('user_id', $user->id)->first()->pivot->role)->toBe('viewer');
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('an existing user accepting an invitation must confirm their password', function () {
    $owner = User::factory()->create();
    $invited_user = User::factory()->create(['email' => 'existing@example.com', 'password' => 'correct-password']);
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'existing@example.com',
        'role' => 'member',
        'invited_by' => $owner->id,
    ]);

    $wrong_password = $this->postJson("/api/auth/invitations/{$invitation->code}/accept", [
        'password' => 'wrong-password',
    ]);
    $wrong_password->assertUnprocessable();

    $correct_password = $this->postJson("/api/auth/invitations/{$invitation->code}/accept", [
        'password' => 'correct-password',
    ]);
    $correct_password->assertOk();

    expect($workspace->fresh()->users()->where('user_id', $invited_user->id)->first()->pivot->role)->toBe('member');
});

test('an expired invitation cannot be accepted', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $invitation = WorkspaceInvitation::factory()->expired()->create([
        'workspace_id' => $workspace->id,
        'email' => 'toolate@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->postJson("/api/auth/invitations/{$invitation->code}/accept", [
        'first_name' => 'Too',
        'last_name' => 'Late',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertUnprocessable();
    expect(User::where('email', 'toolate@example.com')->exists())->toBeFalse();
});

test('an invitation can be declined', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this->postJson("/api/auth/invitations/{$invitation->code}/decline");

    $response->assertOk();
    $this->assertDatabaseMissing('workspace_invitations', ['id' => $invitation->id]);
});
