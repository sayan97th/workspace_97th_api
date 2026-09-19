<?php

use App\Models\BoardComment;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createRevisionTestItem(): BoardItem
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
}

test('editing an item comment keeps the previous body as a revision, newest edit first', function () {
    $item = createRevisionTestItem();
    $author = User::factory()->create();
    $comment = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => 'First draft']);
    $url = "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}";

    $this->actingAs($author, 'api')->patchJson($url, ['body' => 'Second draft'])
        ->assertOk()
        ->assertJsonPath('comment.is_edited', true);
    $this->actingAs($author, 'api')->patchJson($url, ['body' => 'Final draft'])->assertOk();

    $response = $this->actingAs($author, 'api')->getJson("{$url}/revisions")->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.body'))->toBe('Second draft')
        ->and($response->json('data.1.body'))->toBe('First draft')
        ->and($response->json('data.0.edited_by.id'))->toBe($author->id)
        ->and($response->json('data.1.written_at'))->not->toBeNull();
});

test('saving an item comment with the same text is not an edit', function () {
    $item = createRevisionTestItem();
    $author = User::factory()->create();
    $comment = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => 'Same text']);
    $url = "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}";

    $this->actingAs($author, 'api')->patchJson($url, ['body' => 'Same text'])
        ->assertOk()
        ->assertJsonPath('comment.is_edited', false);

    $this->actingAs($author, 'api')->getJson("{$url}/revisions")->assertOk()->assertJsonCount(0, 'data');
});

test('only the author can edit an item comment, so a stranger never adds a revision', function () {
    $item = createRevisionTestItem();
    $author = User::factory()->create();
    $comment = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => 'Mine']);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}", ['body' => 'Hijacked'])
        ->assertForbidden();

    expect($comment->revisions()->count())->toBe(0);
});

test('editing a board discussion update keeps its revisions', function () {
    $item = createRevisionTestItem();
    $board = $item->board;
    $author = User::factory()->create();
    $comment = BoardComment::create(['board_id' => $board->id, 'user_id' => $author->id, 'body' => 'Kickoff notes']);
    $url = "/api/boards/{$board->id}/comments/{$comment->id}";

    $this->actingAs($author, 'api')->patchJson($url, ['body' => 'Kickoff notes, updated'])->assertOk();

    $this->actingAs($author, 'api')->getJson("{$url}/revisions")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'Kickoff notes');
});

test('revisions of a comment on another item are not reachable through this item', function () {
    $item = createRevisionTestItem();
    $other_item = createRevisionTestItem();
    $author = User::factory()->create();
    $comment = BoardItemComment::create(['item_id' => $other_item->id, 'user_id' => $author->id, 'body' => 'Elsewhere']);

    $this->actingAs($author, 'api')
        ->getJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/revisions")
        ->assertNotFound();
});
