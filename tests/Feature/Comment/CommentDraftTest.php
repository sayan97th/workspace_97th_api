<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\CommentDraft;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createDraftBoard(array $attributes = []): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();

    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        ...$attributes,
    ]);
}

function createDraftItem(?WorkspaceNavigationItem $board = null): BoardItem
{
    $board ??= createDraftBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return $board->items()->create(['group_id' => $group->id, 'name' => 'Launch plan', 'position' => 0]);
}

test('an item update draft is saved, restored and replaced in place', function () {
    $item = createDraftItem();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->putJson("/api/boards/{$item->board_id}/comment-drafts", [
        'item_id' => $item->id,
        'body' => 'First words',
        'mentioned_user_ids' => [5, 5, 7],
    ])->assertOk()->assertJsonPath('data.body', 'First words')->assertJsonPath('data.mentioned_user_ids', [5, 7]);

    $this->actingAs($user, 'api')->putJson("/api/boards/{$item->board_id}/comment-drafts", [
        'item_id' => $item->id,
        'body' => 'First words, and more',
    ])->assertOk();

    $this->actingAs($user, 'api')->getJson("/api/boards/{$item->board_id}/comment-drafts")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.item_id', $item->id)
        ->assertJsonPath('data.0.parent_id', null)
        ->assertJsonPath('data.0.body', 'First words, and more');
});

test('reply drafts and the board discussion draft are kept apart from the update draft', function () {
    $item = createDraftItem();
    $user = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $user->id, 'body' => 'Parent update']);
    $url = "/api/boards/{$item->board_id}/comment-drafts";

    $this->actingAs($user, 'api')->putJson($url, ['item_id' => $item->id, 'body' => 'Update draft'])->assertOk();
    $this->actingAs($user, 'api')->putJson($url, ['item_id' => $item->id, 'parent_id' => $comment->id, 'body' => 'Reply draft'])->assertOk();
    $this->actingAs($user, 'api')->putJson($url, ['body' => 'Board discussion draft'])->assertOk();

    expect(CommentDraft::where('user_id', $user->id)->count())->toBe(3);

    $this->actingAs($user, 'api')->deleteJson("{$url}?item_id={$item->id}&parent_id={$comment->id}")->assertOk();

    expect(CommentDraft::where('user_id', $user->id)->pluck('body')->sort()->values()->all())
        ->toBe(['Board discussion draft', 'Update draft']);
});

test('saving an empty body removes the draft', function () {
    $item = createDraftItem();
    $user = User::factory()->create();
    $url = "/api/boards/{$item->board_id}/comment-drafts";

    $this->actingAs($user, 'api')->putJson($url, ['item_id' => $item->id, 'body' => 'Something'])->assertOk();
    $this->actingAs($user, 'api')->putJson($url, ['item_id' => $item->id, 'body' => '   '])->assertOk()->assertJsonPath('data', null);

    $this->assertDatabaseCount('comment_drafts', 0);
});

test('drafts are private to their owner', function () {
    $item = createDraftItem();
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $url = "/api/boards/{$item->board_id}/comment-drafts";

    $this->actingAs($owner, 'api')->putJson($url, ['item_id' => $item->id, 'body' => 'Mine'])->assertOk();
    $draft = CommentDraft::firstOrFail();

    $this->actingAs($stranger, 'api')->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($stranger, 'api')->getJson('/api/comment-drafts')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($stranger, 'api')->deleteJson("/api/comment-drafts/{$draft->id}")->assertForbidden();
    // Discarding by key only ever touches the caller's own draft.
    $this->actingAs($stranger, 'api')->deleteJson("{$url}?item_id={$item->id}")->assertOk();

    $this->assertDatabaseHas('comment_drafts', ['id' => $draft->id, 'body' => 'Mine']);
});

test('a draft must point at an item and an update of the same board', function () {
    $item = createDraftItem();
    $other_item = createDraftItem();
    $user = User::factory()->create();
    $foreign_comment = $other_item->comments()->create(['user_id' => $user->id, 'body' => 'Elsewhere']);
    $url = "/api/boards/{$item->board_id}/comment-drafts";

    $this->actingAs($user, 'api')->putJson($url, ['item_id' => $other_item->id, 'body' => 'x'])->assertUnprocessable();
    $this->actingAs($user, 'api')->putJson($url, ['item_id' => $item->id, 'parent_id' => $foreign_comment->id, 'body' => 'x'])->assertUnprocessable();
    $this->actingAs($user, 'api')->putJson($url, ['item_id' => $item->id, 'body' => str_repeat('a', CommentDraft::MAX_BODY_LENGTH + 1)])->assertUnprocessable();
});

test('the drafts list names the board and item and hides boards the user cannot open', function () {
    $item = createDraftItem();
    $user = User::factory()->create();
    $private_board = createDraftBoard(['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE]);

    $this->actingAs($user, 'api')->putJson("/api/boards/{$item->board_id}/comment-drafts", ['item_id' => $item->id, 'body' => 'Visible'])->assertOk();
    CommentDraft::create([
        'user_id' => $user->id,
        'board_id' => $private_board->id,
        'draft_key' => CommentDraft::keyFor($private_board->id, null, null),
        'body' => 'Hidden',
    ]);

    $this->actingAs($user, 'api')->getJson('/api/comment-drafts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'Visible')
        ->assertJsonPath('data.0.item_name', 'Launch plan')
        ->assertJsonPath('data.0.board_name', $item->board->label);
});

test('a draft of a deleted item no longer shows up', function () {
    $item = createDraftItem();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->putJson("/api/boards/{$item->board_id}/comment-drafts", ['item_id' => $item->id, 'body' => 'Orphan'])->assertOk();
    $item->delete();

    $this->actingAs($user, 'api')->getJson("/api/boards/{$item->board_id}/comment-drafts")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($user, 'api')->getJson('/api/comment-drafts')->assertOk()->assertJsonCount(0, 'data');
});
