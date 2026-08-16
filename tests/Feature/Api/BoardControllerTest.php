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

test('a board reports its total discussion comment count, replies included', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'owner']);
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $comment = $board->comments()->create(['user_id' => $user->id, 'body' => 'Original update']);
    $board->comments()->create(['user_id' => $user->id, 'parent_id' => $comment->id, 'body' => 'A reply']);

    $response = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}");

    $response->assertOk()->assertJsonPath('comments_count', 2);
});

test('a board with no discussion comments reports zero and no unseen updates', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'owner']);
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);

    $response = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}");

    $response->assertOk()
        ->assertJsonPath('comments_count', 0)
        ->assertJsonPath('has_unseen_comments', false);
});

test('a board flags unseen updates from another user until the viewer opens the discussion drawer', function () {
    $author = User::factory()->create();
    $viewer = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach([$author->id, $viewer->id], ['role' => 'owner']);
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $board->comments()->create(['user_id' => $author->id, 'body' => 'Heads up everyone']);

    $before = $this->actingAs($viewer, 'api')->getJson("/api/boards/{$board->id}");
    $before->assertOk()->assertJsonPath('has_unseen_comments', true);

    $this->actingAs($viewer, 'api')->getJson("/api/boards/{$board->id}/comments")->assertOk();

    $after = $this->actingAs($viewer, 'api')->getJson("/api/boards/{$board->id}");
    $after->assertOk()->assertJsonPath('has_unseen_comments', false);
});

test('a user is never flagged as having unseen updates for their own comments', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'owner']);
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $board->comments()->create(['user_id' => $user->id, 'body' => 'Note to self']);

    $response = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}");

    $response->assertOk()->assertJsonPath('has_unseen_comments', false);
});
