<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\BoardTag;
use App\Models\BoardView;
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

test('a tag of a private board cannot be mutated through another board', function () {
    $user = User::factory()->create();
    $board = createTagTestBoard();
    $other_board = createTagTestBoard();
    $other_board->update(['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE]);
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

function tagItemsOnBoard(WorkspaceNavigationItem $board, array $values): void
{
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => 'tags']);

    foreach ($values as $value) {
        $item = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id]);
        BoardItemValue::factory()->create(['item_id' => $item->id, 'column_id' => $column->id, 'value' => $value]);
    }
}

test('the picker lists the board tags first, then public tags of other boards, with usage counts', function () {
    $user = User::factory()->create(['is_active' => true]);
    $board = createTagTestBoard();
    $public_board = createTagTestBoard();
    $private_board = createTagTestBoard();
    $private_board->update(['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE]);

    $own = BoardTag::factory()->create(['board_id' => $board->id, 'label' => '#seo']);
    $shared = BoardTag::factory()->create(['board_id' => $public_board->id, 'label' => '#ppc']);
    BoardTag::factory()->create(['board_id' => $public_board->id, 'label' => '#SEO']);
    BoardTag::factory()->create(['board_id' => $private_board->id, 'label' => '#secret']);

    tagItemsOnBoard($board, [[(string) $own->id], [(string) $own->id, (string) $shared->id]]);
    tagItemsOnBoard($public_board, [[(string) $shared->id]]);

    $response = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/tags")->assertOk();

    expect(collect($response->json('data'))->map(fn ($tag) => [$tag['label'], $tag['usage_count']])->all())
        ->toBe([['#seo', 2], ['#ppc', 2]]);
});

test('older cells that store the raw label still count for the tag of their board', function () {
    $user = User::factory()->create();
    $board = createTagTestBoard();
    BoardTag::factory()->create(['board_id' => $board->id, 'label' => '#design']);
    tagItemsOnBoard($board, [['design'], ['other']]);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/tags")
        ->assertJsonPath('data.0.usage_count', 1);
});

test('creating a tag on a main board reuses an existing public tag with the same label', function () {
    $user = User::factory()->create();
    $board = createTagTestBoard();
    $other_board = createTagTestBoard();
    $existing = BoardTag::factory()->create(['board_id' => $other_board->id, 'label' => '#seo']);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/tags", ['label' => '#SEO', 'color' => '#579bfc'])
        ->assertOk()
        ->assertJsonPath('tag.id', $existing->id);

    expect(BoardTag::where('board_id', $board->id)->count())->toBe(0);
});

test('a private board creates its own tag even when a public one has the same label', function () {
    $user = User::factory()->create();
    $board = createTagTestBoard();
    $board->update(['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE, 'created_by_id' => $user->id]);
    BoardTag::factory()->create(['board_id' => createTagTestBoard()->id, 'label' => '#seo']);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/tags", ['label' => '#seo', 'color' => '#579bfc'])
        ->assertCreated()
        ->assertJsonPath('tag.board_id', $board->id);
});

test('a shared public tag can be recolored from another board', function () {
    $user = User::factory()->create();
    $board = createTagTestBoard();
    $tag = BoardTag::factory()->create(['board_id' => createTagTestBoard()->id, 'color' => '#000000']);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/tags/{$tag->id}", ['color' => '#ff0000'])
        ->assertOk()
        ->assertJsonPath('tag.color', '#ff0000');
});
