<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

test('a board can be resolved by id alone, with its workspace and breadcrumb', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['slug' => 'fulfillment', 'name' => 'Fulfillment']);
    $workspace->users()->attach($user->id, ['role' => 'owner']);

    $group = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_GROUP,
        'label' => 'Development',
        'parent_id' => null,
    ]);

    $leaf = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'label' => 'Sprints',
        'parent_id' => $group->id,
    ]);

    $response = $this->actingAs($user, 'api')->getJson("/api/boards/{$leaf->id}");

    $response->assertOk()
        ->assertJsonPath('id', $leaf->id)
        ->assertJsonPath('label', 'Sprints')
        ->assertJsonPath('workspace.slug', 'fulfillment')
        ->assertJsonPath('workspace.name', 'Fulfillment')
        ->assertJsonPath('breadcrumb.0.id', $group->id)
        ->assertJsonPath('breadcrumb.0.label', 'Development')
        ->assertJsonCount(1, 'breadcrumb');
});

test('a root-level board has an empty breadcrumb', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'owner']);

    $item = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'parent_id' => null,
    ]);

    $response = $this->actingAs($user, 'api')->getJson("/api/boards/{$item->id}");

    $response->assertOk()->assertJsonCount(0, 'breadcrumb');
});

test('an unknown board id returns a 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->getJson('/api/boards/999999')
        ->assertNotFound();
});
