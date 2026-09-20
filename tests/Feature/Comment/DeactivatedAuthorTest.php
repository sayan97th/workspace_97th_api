<?php

use App\Models\BoardGroup;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createDeactivatedAuthorItem(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return [$board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]), $workspace];
}

test('comments from a deleted user keep the author and flag it as deactivated', function () {
    [$item, $workspace] = createDeactivatedAuthorItem();
    $author = User::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);
    $viewer = User::factory()->create();
    $workspace->users()->attach([$author->id => ['role' => 'member'], $viewer->id => ['role' => 'member']]);

    $this->actingAs($author, 'api')
        ->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", ['body' => 'Hello'])
        ->assertCreated();

    $author->delete();

    $this->actingAs($viewer, 'api')
        ->getJson("/api/boards/{$item->board_id}/items/{$item->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.author.id', $author->id)
        ->assertJsonPath('data.0.author.full_name', 'Grace Hopper')
        ->assertJsonPath('data.0.author.is_deactivated', true);
});

test('comments from a disabled user are flagged as deactivated too', function () {
    [$item, $workspace] = createDeactivatedAuthorItem();
    $author = User::factory()->create();
    $viewer = User::factory()->create();
    $workspace->users()->attach([$author->id => ['role' => 'member'], $viewer->id => ['role' => 'member']]);

    $this->actingAs($author, 'api')
        ->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", ['body' => 'Hello'])
        ->assertCreated();

    $author->update(['is_active' => false]);

    $this->actingAs($viewer, 'api')
        ->getJson("/api/boards/{$item->board_id}/items/{$item->id}/comments")
        ->assertJsonPath('data.0.author.is_deactivated', true);
});

test('the workspace roster only includes deleted members when asked to', function () {
    $workspace = Workspace::factory()->create();
    $viewer = User::factory()->create();
    $gone = User::factory()->create();
    $workspace->users()->attach([$viewer->id => ['role' => 'owner'], $gone->id => ['role' => 'member']]);
    $gone->delete();

    $this->actingAs($viewer, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/members")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($viewer, 'api')
        ->getJson("/api/workspaces/{$workspace->slug}/members?include_deactivated=1")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['id' => $gone->id, 'is_deactivated' => true]);
});

test('the person card of a deleted user still resolves and is flagged', function () {
    $workspace = Workspace::factory()->create();
    $viewer = User::factory()->create();
    $gone = User::factory()->create();
    $workspace->users()->attach([$viewer->id => ['role' => 'member'], $gone->id => ['role' => 'member']]);
    $gone->delete();

    $this->actingAs($viewer, 'api')
        ->getJson("/api/people/{$gone->id}/card")
        ->assertOk()
        ->assertJsonPath('data.is_deactivated', true);
});
