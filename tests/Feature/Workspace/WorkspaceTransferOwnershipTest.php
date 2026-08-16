<?php

use App\Models\User;
use App\Models\Workspace;

test('the owner can transfer ownership and keep a role in the workspace', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/transfer-ownership", [
            'new_owner_id' => $member->id,
            'self_role' => 'viewer',
        ]);

    $response->assertOk()->assertJsonPath('left', false);

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => 'owner',
    ]);
    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'role' => 'viewer',
    ]);
});

test('the owner can transfer ownership and leave the workspace', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/transfer-ownership", [
            'new_owner_id' => $member->id,
            'self_role' => 'leave',
        ]);

    $response->assertOk()->assertJsonPath('left', true);

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => 'owner',
    ]);
    $this->assertDatabaseMissing('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);
});

test('a non-owner cannot transfer ownership', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $other = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);
    $workspace->users()->attach($other->id, ['role' => 'member']);

    $response = $this->actingAs($member, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/transfer-ownership", [
            'new_owner_id' => $other->id,
            'self_role' => 'member',
        ]);

    $response->assertStatus(403);
});

test('ownership cannot be transferred to yourself', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/transfer-ownership", [
            'new_owner_id' => $owner->id,
            'self_role' => 'member',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('new_owner_id');
});

test('ownership cannot be transferred to someone outside the workspace', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/transfer-ownership", [
            'new_owner_id' => $outsider->id,
            'self_role' => 'member',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('new_owner_id');
});

test('self_role must be member, viewer, or leave', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/transfer-ownership", [
            'new_owner_id' => $member->id,
            'self_role' => 'owner',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('self_role');
});
