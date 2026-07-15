<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

test('a navigation item defaults to the main board type and exposes workspace owners', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'owner']);

    $item = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $response = $this->actingAs($user, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/navigation");

    $response->assertOk()
        ->assertJsonPath('data.0.id', $item->id)
        ->assertJsonPath('data.0.board_type', 'main')
        ->assertJsonPath('data.0.owners.0.id', $user->id)
        ->assertJsonPath('data.0.owners.0.full_name', $user->full_name);
});

test('a navigation item board type can be created and updated', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'owner']);

    $create_response = $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/navigation", [
            'type' => WorkspaceNavigationItem::TYPE_LEAF,
            'label' => 'Client Hub',
            'board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
        ]);

    $create_response->assertCreated()->assertJsonPath('item.board_type', 'private');

    $item_id = $create_response->json('item.id');

    $update_response = $this->actingAs($user, 'api')
        ->patchJson("/api/workspaces/{$workspace->slug}/navigation/{$item_id}", [
            'board_type' => WorkspaceNavigationItem::BOARD_TYPE_SHAREABLE,
        ]);

    $update_response->assertOk()->assertJsonPath('item.board_type', 'shareable');

    expect(WorkspaceNavigationItem::find($item_id)->board_type)->toBe('shareable');
});

test('an invalid board type is rejected', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/navigation", [
            'type' => WorkspaceNavigationItem::TYPE_LEAF,
            'label' => 'Client Hub',
            'board_type' => 'bogus',
        ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('board_type');
});
