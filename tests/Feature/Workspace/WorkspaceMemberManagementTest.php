<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;

test('the owner can change a member\'s role', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create(['created_by' => $owner->id]);
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);

    $response = $this->actingAs($owner, 'api')
        ->patchJson("/api/workspaces/{$workspace->slug}/members/{$member->id}", [
            'role' => 'viewer',
        ]);

    $response->assertOk()->assertJsonPath('data.role', 'viewer');

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => 'viewer',
    ]);
});

test('the owner can remove a member', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create(['created_by' => $owner->id]);
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/workspaces/{$workspace->slug}/members/{$member->id}");

    $response->assertOk();

    $this->assertDatabaseMissing('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
    ]);
});

test('a non-owner cannot change roles or remove members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $other = User::factory()->create();
    $workspace = Workspace::factory()->create(['created_by' => $owner->id]);
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);
    $workspace->users()->attach($other->id, ['role' => 'member']);

    $this->actingAs($member, 'api')
        ->patchJson("/api/workspaces/{$workspace->slug}/members/{$other->id}", ['role' => 'viewer'])
        ->assertStatus(403);

    $this->actingAs($member, 'api')
        ->deleteJson("/api/workspaces/{$workspace->slug}/members/{$other->id}")
        ->assertStatus(403);
});

test('the workspace creator can never be removed, even after ownership transfer', function () {
    $creator = User::factory()->create();
    $new_owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['created_by' => $creator->id]);
    $workspace->users()->attach($creator->id, ['role' => 'owner']);
    $workspace->users()->attach($new_owner->id, ['role' => 'owner']);

    // Demote the creator away from "owner" first, so the removal attempt below
    // is only ever blocked by the creator check, not the sole-owner check.
    $workspace->users()->updateExistingPivot($creator->id, ['role' => 'member']);

    $response = $this->actingAs($new_owner, 'api')
        ->deleteJson("/api/workspaces/{$workspace->slug}/members/{$creator->id}");

    $response->assertStatus(403)->assertJsonValidationErrors('member');

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $creator->id,
    ]);
});

test('a member cannot change or remove their own membership through these endpoints', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['created_by' => $owner->id]);
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $this->actingAs($owner, 'api')
        ->patchJson("/api/workspaces/{$workspace->slug}/members/{$owner->id}", ['role' => 'member'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('member');

    $this->actingAs($owner, 'api')
        ->deleteJson("/api/workspaces/{$workspace->slug}/members/{$owner->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('member');
});

test('the sole remaining owner cannot be demoted or removed', function () {
    Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Administrator']);

    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $member = User::factory()->create();
    // `created_by` deliberately isn't the owner here, so these assertions
    // isolate the sole-owner check from the separate creator-protection check.
    $workspace = Workspace::factory()->create(['created_by' => $member->id]);
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);

    $this->actingAs($admin, 'api')
        ->patchJson("/api/workspaces/{$workspace->slug}/members/{$owner->id}", ['role' => 'member'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('member');

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/workspaces/{$workspace->slug}/members/{$owner->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('member');
});

test('removing someone who is not a member returns a 404', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $workspace = Workspace::factory()->create(['created_by' => $owner->id]);
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $this->actingAs($owner, 'api')
        ->deleteJson("/api/workspaces/{$workspace->slug}/members/{$outsider->id}")
        ->assertStatus(404);
});
