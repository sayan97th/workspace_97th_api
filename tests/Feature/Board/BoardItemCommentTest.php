<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createCommentTestItem(): BoardItem
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

test('a comment can be posted with mentions and an attachment', function () {
    Storage::fake('public');
    $item = createCommentTestItem();
    $user = User::factory()->create();
    $mentioned = User::factory()->create();

    $response = $this->actingAs($user, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments",
        [
            'body' => 'Please take a look @'.$mentioned->full_name,
            'mentioned_user_ids' => [$mentioned->id],
            'attachments' => [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')],
        ],
        ['Accept' => 'application/json']
    );

    $response->assertCreated()
        ->assertJsonPath('comment.body', 'Please take a look @'.$mentioned->full_name)
        ->assertJsonPath('comment.author.id', $user->id)
        ->assertJsonCount(1, 'comment.mentioned_user_ids')
        ->assertJsonCount(1, 'comment.attachments')
        ->assertJsonPath('comment.attachments.0.file_name', 'notes.pdf');

    $this->assertDatabaseHas('board_item_comments', ['item_id' => $item->id, 'user_id' => $user->id]);
    $this->assertDatabaseHas('board_item_comment_mentions', ['user_id' => $mentioned->id]);

    $attachment = \App\Models\BoardItemCommentAttachment::firstOrFail();
    Storage::disk('public')->assertExists($attachment->file_path);
});

test('a reply can be posted under a top-level comment and is nested one level', function () {
    $item = createCommentTestItem();
    $author = User::factory()->create();
    $replier = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Original update']);

    $response = $this->actingAs($replier, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments",
        ['body' => 'Replying here', 'parent_id' => $comment->id]
    );

    $response->assertCreated()->assertJsonPath('comment.parent_id', $comment->id);

    $index_response = $this->actingAs($author, 'api')
        ->getJson("/api/boards/{$item->board_id}/items/{$item->id}/comments");

    $index_response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(1, 'data.0.replies')
        ->assertJsonPath('data.0.replies.0.body', 'Replying here');
});

test('replying to a reply is rejected', function () {
    $item = createCommentTestItem();
    $user = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $user->id, 'body' => 'Original update']);
    $reply = $item->comments()->create(['user_id' => $user->id, 'parent_id' => $comment->id, 'body' => 'A reply']);

    $response = $this->actingAs($user, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments",
        ['body' => 'Nested too deep', 'parent_id' => $reply->id]
    );

    $response->assertUnprocessable()->assertJsonValidationErrors('parent_id');
});

test('liking a comment toggles on and off', function () {
    $item = createCommentTestItem();
    $user = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $user->id, 'body' => 'Original update']);

    $on = $this->actingAs($user, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/like"
    );
    $on->assertOk()->assertJsonPath('comment.liked_by_me', true)->assertJsonPath('comment.like_count', 1);

    $off = $this->actingAs($user, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/like"
    );
    $off->assertOk()->assertJsonPath('comment.liked_by_me', false)->assertJsonPath('comment.like_count', 0);
});

test('reacting with an emoji toggles on and off and rejects an unlisted emoji', function () {
    $item = createCommentTestItem();
    $user = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $user->id, 'body' => 'Original update']);

    $on = $this->actingAs($user, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/reactions",
        ['emoji' => '🔥']
    );
    $on->assertOk()->assertJsonCount(1, 'comment.reactions')->assertJsonPath('comment.reactions.0.reacted_by_me', true);

    $off = $this->actingAs($user, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/reactions",
        ['emoji' => '🔥']
    );
    $off->assertOk()->assertJsonCount(0, 'comment.reactions');

    $invalid = $this->actingAs($user, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/reactions",
        ['emoji' => '🍕']
    );
    $invalid->assertUnprocessable();
});

test('marking a comment as seen toggles the view count', function () {
    $item = createCommentTestItem();
    $user = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $user->id, 'body' => 'Original update']);

    $on = $this->actingAs($user, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/seen"
    );
    $on->assertOk()->assertJsonPath('comment.seen_by_me', true)->assertJsonPath('comment.view_count', 1);
});

test('a comment can only be deleted by its author', function () {
    $item = createCommentTestItem();
    $author = User::factory()->create();
    $other = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Original update']);

    $this->actingAs($other, 'api')
        ->deleteJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}")
        ->assertForbidden();

    $this->actingAs($author, 'api')
        ->deleteJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}")
        ->assertOk();

    $this->assertSoftDeleted('board_item_comments', ['id' => $comment->id]);
});
