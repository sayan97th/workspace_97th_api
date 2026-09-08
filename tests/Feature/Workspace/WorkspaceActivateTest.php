<?php

use App\Models\User;
use App\Models\Workspace;

test('a member can activate a workspace and it is remembered as their last active one', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'member']);

    $response = $this->actingAs($user, 'api')
        ->patchJson("/api/workspaces/{$workspace->slug}/activate");

    $response->assertOk()->assertJsonPath('last_active_workspace_id', $workspace->id);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'last_active_workspace_id' => $workspace->id,
    ]);
});

test('activating a workspace switches away from the previously active one', function () {
    $user = User::factory()->create();
    $first = Workspace::factory()->create();
    $second = Workspace::factory()->create();
    $first->users()->attach($user->id, ['role' => 'member']);
    $second->users()->attach($user->id, ['role' => 'member']);

    $this->actingAs($user, 'api')->patchJson("/api/workspaces/{$first->slug}/activate")->assertOk();
    $this->actingAs($user, 'api')->patchJson("/api/workspaces/{$second->slug}/activate")->assertOk();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'last_active_workspace_id' => $second->id,
    ]);
});

test('the profile endpoint exposes the last active workspace id', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'member']);
    $user->setLastActiveWorkspace($workspace);

    $response = $this->actingAs($user, 'api')->getJson('/api/auth/me');

    $response->assertOk()->assertJsonPath('user.last_active_workspace_id', $workspace->id);
});
