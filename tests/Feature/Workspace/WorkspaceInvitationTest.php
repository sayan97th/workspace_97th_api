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

test('a global admin can invite members to a workspace they do not belong to', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $response = $this->actingAs($admin, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invitations", [
            'emails' => ['invited@example.com'],
            'role' => 'viewer',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.0.email', 'invited@example.com');
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

test('an invitation preview includes the inviter\'s custom message', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
        'message' => 'Welcome aboard!',
    ]);

    $this->getJson("/api/auth/invitations/{$invitation->code}")
        ->assertOk()
        ->assertJsonPath('message', 'Welcome aboard!');
});

test('the workspace invitation email renders the workspace branding, message, and accept link', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['name' => 'Acme Studio', 'mono' => 'AS', 'color' => '#e53e2e']);
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'invited@example.com',
        'role' => 'viewer',
        'invited_by' => $owner->id,
        'message' => 'Welcome aboard!',
    ]);

    (new WorkspaceInvitationMail($invitation))
        ->assertHasSubject("You've been invited to join Acme Studio")
        ->assertSeeInHtml('Acme Studio')
        ->assertSeeInHtml('AS')
        ->assertSeeInHtml('Welcome aboard!')
        ->assertSeeInHtml("/invitations/{$invitation->code}", false);
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

test('a workspace owner can list every sent invitation, newest first', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $older = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'older@example.com',
        'invited_by' => $owner->id,
        'created_at' => now()->subDay(),
    ]);
    $newer = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'newer@example.com',
        'invited_by' => $owner->id,
        'created_at' => now(),
    ]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/invitations");

    $response->assertOk()
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('data.0.inviter.id', $owner->id)
        ->assertJsonPath('meta.total', 2);
});

test('a global super admin can list a workspace\'s sent invitations without being a member', function () {
    $owner = User::factory()->create();
    $super_admin = User::factory()->create();
    $super_admin->assignRole('super_admin');
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this->actingAs($super_admin, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/invitations");

    $response->assertOk()->assertJsonPath('meta.total', 1);
});

test('staff without a privileged role cannot list a workspace\'s sent invitations', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $staff->assignRole('staff');
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $response = $this->actingAs($staff, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/invitations");

    $response->assertStatus(403);
});

test('members cannot list a workspace\'s sent invitations', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);

    $response = $this->actingAs($member, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/invitations");

    $response->assertStatus(403);
});

test('sent invitations can be searched by email and filtered by status/role', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $pending = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'pending@example.com',
        'role' => 'viewer',
        'invited_by' => $owner->id,
    ]);
    WorkspaceInvitation::factory()->expired()->create([
        'workspace_id' => $workspace->id,
        'email' => 'expired@example.com',
        'role' => 'member',
        'invited_by' => $owner->id,
    ]);
    WorkspaceInvitation::factory()->accepted()->create([
        'workspace_id' => $workspace->id,
        'email' => 'accepted@example.com',
        'role' => 'member',
        'invited_by' => $owner->id,
    ]);

    $search = $this->actingAs($owner, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/invitations?search=pending");
    $search->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pending->id)
        ->assertJsonPath('data.0.status', 'pending');

    $status_filter = $this->actingAs($owner, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/invitations?status=expired");
    $status_filter->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'expired@example.com')
        ->assertJsonPath('data.0.status', 'expired');

    $role_filter = $this->actingAs($owner, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/invitations?role=viewer");
    $role_filter->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'pending@example.com');
});

test('sent invitations can be sorted by any column in either direction', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'bravo@example.com',
        'invited_by' => $owner->id,
    ]);
    WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'alpha@example.com',
        'invited_by' => $owner->id,
    ]);

    $ascending = $this->actingAs($owner, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/invitations?sort_field=email&sort_direction=asc");
    $ascending->assertOk()
        ->assertJsonPath('data.0.email', 'alpha@example.com')
        ->assertJsonPath('data.1.email', 'bravo@example.com');

    $descending = $this->actingAs($owner, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/invitations?sort_field=email&sort_direction=desc");
    $descending->assertOk()
        ->assertJsonPath('data.0.email', 'bravo@example.com')
        ->assertJsonPath('data.1.email', 'alpha@example.com');
});

test('sent invitations can be narrowed to a date range', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $old = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'old@example.com',
        'invited_by' => $owner->id,
        'created_at' => now()->subDays(10),
    ]);
    $recent = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'recent@example.com',
        'invited_by' => $owner->id,
        'created_at' => now(),
    ]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/invitations?date_from=".now()->subDays(2)->toDateString());

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $recent->id);

    expect($old->id)->not->toBe($recent->id);
});

test('a workspace owner can revoke a pending invitation', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/workspaces/{$workspace->slug}/invitations/{$invitation->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('workspace_invitations', ['id' => $invitation->id]);
});

test('a global admin can revoke a workspace\'s invitation without being a member', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this->actingAs($admin, 'api')
        ->deleteJson("/api/workspaces/{$workspace->slug}/invitations/{$invitation->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('workspace_invitations', ['id' => $invitation->id]);
});

test('an accepted invitation cannot be revoked', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $invitation = WorkspaceInvitation::factory()->accepted()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/workspaces/{$workspace->slug}/invitations/{$invitation->id}");

    $response->assertUnprocessable();
    $this->assertDatabaseHas('workspace_invitations', ['id' => $invitation->id]);
});

test('members cannot revoke a workspace\'s invitations', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this->actingAs($member, 'api')
        ->deleteJson("/api/workspaces/{$workspace->slug}/invitations/{$invitation->id}");

    $response->assertStatus(403);
    $this->assertDatabaseHas('workspace_invitations', ['id' => $invitation->id]);
});

test('an invitation cannot be revoked through a workspace it does not belong to', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $other_workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $other_workspace->users()->attach($owner->id, ['role' => 'owner']);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/workspaces/{$other_workspace->slug}/invitations/{$invitation->id}");

    $response->assertNotFound();
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
