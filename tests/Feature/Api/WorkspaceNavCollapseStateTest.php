<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

test('a workspace has no collapsed sidebar folders by default', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'owner']);
    WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_GROUP,
    ]);

    $this->actingAs($user, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/navigation")
        ->assertOk()
        ->assertJsonPath('collapsed_group_ids', []);
});

test('a viewer can save which sidebar folders are collapsed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'owner']);
    $folder_one = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_GROUP,
    ]);
    $folder_two = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_GROUP,
    ]);

    $this->actingAs($user, 'api')
        ->putJson("/api/workspaces/{$workspace->slug}/navigation/collapsed-state", [
            'collapsed_group_ids' => [$folder_one->id],
        ])
        ->assertOk()
        ->assertJsonPath('collapsed_group_ids', [$folder_one->id]);

    $this->actingAs($user, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/navigation")
        ->assertOk()
        ->assertJsonPath('collapsed_group_ids', [$folder_one->id]);

    $this->assertDatabaseHas('workspace_nav_collapse_states', [
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'collapsed_group_ids' => json_encode([$folder_one->id]),
    ]);

    // Saving again fully replaces the previous set rather than merging into it.
    $this->actingAs($user, 'api')
        ->putJson("/api/workspaces/{$workspace->slug}/navigation/collapsed-state", [
            'collapsed_group_ids' => [$folder_two->id],
        ])
        ->assertOk()
        ->assertJsonPath('collapsed_group_ids', [$folder_two->id]);
});

test('collapsed-folder state is personal — one viewer collapsing a folder does not affect another', function () {
    $user_one = User::factory()->create();
    $user_two = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user_one->id, ['role' => 'owner']);
    $workspace->users()->attach($user_two->id, ['role' => 'member']);
    $folder = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_GROUP,
    ]);

    $this->actingAs($user_one, 'api')
        ->putJson("/api/workspaces/{$workspace->slug}/navigation/collapsed-state", [
            'collapsed_group_ids' => [$folder->id],
        ])
        ->assertOk();

    $this->actingAs($user_two, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/navigation")
        ->assertOk()
        ->assertJsonPath('collapsed_group_ids', []);
});

test('a folder id belonging to a different workspace is ignored when saving collapsed state', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $other_workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'owner']);
    $folder_in_workspace = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_GROUP,
    ]);
    $folder_elsewhere = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $other_workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_GROUP,
    ]);

    $this->actingAs($user, 'api')
        ->putJson("/api/workspaces/{$workspace->slug}/navigation/collapsed-state", [
            'collapsed_group_ids' => [$folder_in_workspace->id, $folder_elsewhere->id],
        ])
        ->assertOk()
        ->assertJsonPath('collapsed_group_ids', [$folder_in_workspace->id]);
});
