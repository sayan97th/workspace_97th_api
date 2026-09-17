<?php

use App\Models\BoardTag;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createTagTestBoard(): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();

    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
}

test('a board starts with no tags', function () {
    $user = User::factory()->create();
    $board = createTagTestBoard();

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/tags")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('a tag can be created and lands at the end of the board list', function () {
    $user = User::factory()->create();
    $board = createTagTestBoard();
    BoardTag::factory()->create(['board_id' => $board->id, 'label' => '#existing', 'position' => 0]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/tags", [
        'label' => '#new-tag',
        'color' => '#579bfc',
    ]);

    $response->assertCreated()
        ->assertJsonPath('tag.label', '#new-tag')
        ->assertJsonPath('tag.color', '#579bfc')
        ->assertJsonPath('tag.position', 1);
});

test('a tag label must be unique per board but not across boards', function () {
    $user = User::factory()->create();
    $board = createTagTestBoard();
    $other_board = createTagTestBoard();
    BoardTag::factory()->create(['board_id' => $board->id, 'label' => '#dup']);
    BoardTag::factory()->create(['board_id' => $other_board->id, 'label' => '#dup']);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/tags", ['label' => '#dup', 'color' => '#579bfc'])
        ->assertUnprocessable();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$other_board->id}/tags", ['label' => '#brand-new', 'color' => '#579bfc'])
        ->assertCreated();
});

test('a tag can be recolored and renamed', function () {
    $user = User::factory()->create();
    $board = createTagTestBoard();
    $tag = BoardTag::factory()->create(['board_id' => $board->id, 'label' => '#old', 'color' => '#000000']);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/tags/{$tag->id}", ['label' => '#renamed', 'color' => '#ff0000'])
        ->assertOk()
        ->assertJsonPath('tag.label', '#renamed')
        ->assertJsonPath('tag.color', '#ff0000');
});

test('a tag can be deleted', function () {
    $user = User::factory()->create();
    $board = createTagTestBoard();
    $tag = BoardTag::factory()->create(['board_id' => $board->id]);

    $this->actingAs($user, 'api')
        ->deleteJson("/api/boards/{$board->id}/tags/{$tag->id}")
        ->assertOk();

    expect(BoardTag::find($tag->id))->toBeNull();
});

test('a tag belonging to a different board cannot be mutated through this board', function () {
    $user = User::factory()->create();
    $board = createTagTestBoard();
    $other_board = createTagTestBoard();
    $tag = BoardTag::factory()->create(['board_id' => $other_board->id]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/tags/{$tag->id}", ['label' => '#hijacked'])
        ->assertNotFound();
});

test('a workspace viewer cannot create, update, or delete tags', function () {
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $viewer = User::factory()->create();
    $workspace->users()->attach($viewer->id, ['role' => 'viewer']);
    $tag = BoardTag::factory()->create(['board_id' => $board->id]);

    $this->actingAs($viewer, 'api')
        ->postJson("/api/boards/{$board->id}/tags", ['label' => '#nope', 'color' => '#579bfc'])
        ->assertForbidden();

    $this->actingAs($viewer, 'api')
        ->patchJson("/api/boards/{$board->id}/tags/{$tag->id}", ['label' => '#nope'])
        ->assertForbidden();

    $this->actingAs($viewer, 'api')
        ->deleteJson("/api/boards/{$board->id}/tags/{$tag->id}")
        ->assertForbidden();
});
