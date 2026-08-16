<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createCommentTestBoard(): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();

    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
}

test('a comment can be posted with mentions and an attachment', function () {
    Storage::fake('public');
    $board = createCommentTestBoard();
    $user = User::factory()->create();
    $mentioned = User::factory()->create();

    $response = $this->actingAs($user, 'api')->post(
        "/api/boards/{$board->id}/comments",
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

    $this->assertDatabaseHas('board_comments', ['board_id' => $board->id, 'user_id' => $user->id]);
    $this->assertDatabaseHas('board_comment_mentions', ['user_id' => $mentioned->id]);

    $attachment = \App\Models\BoardCommentAttachment::firstOrFail();
    Storage::disk('public')->assertExists($attachment->file_path);
});

test('a comment can be posted with only an attachment and no body text', function () {
    Storage::fake('public');
    $board = createCommentTestBoard();
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->post(
        "/api/boards/{$board->id}/comments",
        ['attachments' => [UploadedFile::fake()->image('photo.png')]],
        ['Accept' => 'application/json']
    );

    $response->assertCreated()
        ->assertJsonPath('comment.body', '')
        ->assertJsonCount(1, 'comment.attachments')
        ->assertJsonPath('comment.attachments.0.file_name', 'photo.png');

    $this->assertDatabaseHas('board_comments', ['board_id' => $board->id, 'user_id' => $user->id, 'body' => '']);
});

test('a comment with neither body text nor an attachment is rejected', function () {
    $board = createCommentTestBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/comments", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

test('a reply can be posted under a top-level comment and is nested one level', function () {
    $board = createCommentTestBoard();
    $author = User::factory()->create();
    $replier = User::factory()->create();
    $comment = $board->comments()->create(['user_id' => $author->id, 'body' => 'Original update']);

    $response = $this->actingAs($replier, 'api')->postJson(
        "/api/boards/{$board->id}/comments",
        ['body' => 'Replying here', 'parent_id' => $comment->id]
    );

    $response->assertCreated()->assertJsonPath('comment.parent_id', $comment->id);

    $index_response = $this->actingAs($author, 'api')->getJson("/api/boards/{$board->id}/comments");

    $index_response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(1, 'data.0.replies')
        ->assertJsonPath('data.0.replies.0.body', 'Replying here');
});

test('replying to a reply is rejected', function () {
    $board = createCommentTestBoard();
    $user = User::factory()->create();
    $comment = $board->comments()->create(['user_id' => $user->id, 'body' => 'Original update']);
    $reply = $board->comments()->create(['user_id' => $user->id, 'parent_id' => $comment->id, 'body' => 'A reply']);

    $response = $this->actingAs($user, 'api')->postJson(
        "/api/boards/{$board->id}/comments",
        ['body' => 'Nested too deep', 'parent_id' => $reply->id]
    );

    $response->assertUnprocessable()->assertJsonValidationErrors('parent_id');
});

test('liking a comment toggles on and off', function () {
    $board = createCommentTestBoard();
    $user = User::factory()->create();
    $comment = $board->comments()->create(['user_id' => $user->id, 'body' => 'Original update']);

    $on = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/comments/{$comment->id}/like");
    $on->assertOk()->assertJsonPath('comment.liked_by_me', true)->assertJsonPath('comment.like_count', 1);

    $off = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/comments/{$comment->id}/like");
    $off->assertOk()->assertJsonPath('comment.liked_by_me', false)->assertJsonPath('comment.like_count', 0);
});

test('reacting with an emoji toggles on and off and rejects an unlisted emoji', function () {
    $board = createCommentTestBoard();
    $user = User::factory()->create();
    $comment = $board->comments()->create(['user_id' => $user->id, 'body' => 'Original update']);

    $on = $this->actingAs($user, 'api')->postJson(
        "/api/boards/{$board->id}/comments/{$comment->id}/reactions",
        ['emoji' => '🔥']
    );
    $on->assertOk()->assertJsonCount(1, 'comment.reactions')->assertJsonPath('comment.reactions.0.reacted_by_me', true);

    $off = $this->actingAs($user, 'api')->postJson(
        "/api/boards/{$board->id}/comments/{$comment->id}/reactions",
        ['emoji' => '🔥']
    );
    $off->assertOk()->assertJsonCount(0, 'comment.reactions');

    $invalid = $this->actingAs($user, 'api')->postJson(
        "/api/boards/{$board->id}/comments/{$comment->id}/reactions",
        ['emoji' => '🍕']
    );
    $invalid->assertUnprocessable();
});

test('marking a comment as seen toggles the view count', function () {
    $board = createCommentTestBoard();
    $user = User::factory()->create();
    $comment = $board->comments()->create(['user_id' => $user->id, 'body' => 'Original update']);

    $on = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/comments/{$comment->id}/seen");
    $on->assertOk()->assertJsonPath('comment.seen_by_me', true)->assertJsonPath('comment.view_count', 1);
});

test('a comment can only be edited by its author', function () {
    $board = createCommentTestBoard();
    $author = User::factory()->create();
    $other = User::factory()->create();
    $comment = $board->comments()->create(['user_id' => $author->id, 'body' => 'Original update']);

    $this->actingAs($other, 'api')
        ->patchJson("/api/boards/{$board->id}/comments/{$comment->id}", ['body' => 'Hacked'])
        ->assertForbidden();

    $response = $this->actingAs($author, 'api')->patchJson(
        "/api/boards/{$board->id}/comments/{$comment->id}",
        ['body' => 'Edited update']
    );

    $response->assertOk()
        ->assertJsonPath('comment.body', 'Edited update')
        ->assertJsonPath('comment.is_edited', true);

    $this->assertDatabaseHas('board_comments', ['id' => $comment->id, 'body' => 'Edited update']);
});

test('editing a comment requires a non-empty body', function () {
    $board = createCommentTestBoard();
    $author = User::factory()->create();
    $comment = $board->comments()->create(['user_id' => $author->id, 'body' => 'Original update']);

    $this->actingAs($author, 'api')
        ->patchJson("/api/boards/{$board->id}/comments/{$comment->id}", ['body' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

test('a comment can only be deleted by its author', function () {
    $board = createCommentTestBoard();
    $author = User::factory()->create();
    $other = User::factory()->create();
    $comment = $board->comments()->create(['user_id' => $author->id, 'body' => 'Original update']);

    $this->actingAs($other, 'api')
        ->deleteJson("/api/boards/{$board->id}/comments/{$comment->id}")
        ->assertForbidden();

    $this->actingAs($author, 'api')
        ->deleteJson("/api/boards/{$board->id}/comments/{$comment->id}")
        ->assertOk();

    $this->assertSoftDeleted('board_comments', ['id' => $comment->id]);
});

test('deleting a comment removes its attachment files from storage', function () {
    Storage::fake('public');
    $board = createCommentTestBoard();
    $author = User::factory()->create();
    $comment = $board->comments()->create(['user_id' => $author->id, 'body' => 'Original update']);
    $path = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')
        ->storeAs("board-discussion-attachments/{$board->id}", 'notes.pdf', 'public');
    $comment->attachments()->create([
        'uploaded_by_id' => $author->id,
        'file_name' => 'notes.pdf',
        'file_path' => $path,
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
    ]);

    Storage::disk('public')->assertExists($path);

    $this->actingAs($author, 'api')
        ->deleteJson("/api/boards/{$board->id}/comments/{$comment->id}")
        ->assertOk();

    Storage::disk('public')->assertMissing($path);
});

test('a comment from another board is not reachable through this board', function () {
    $board = createCommentTestBoard();
    $other_board = createCommentTestBoard();
    $user = User::factory()->create();
    $comment = $other_board->comments()->create(['user_id' => $user->id, 'body' => 'Belongs elsewhere']);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/comments/{$comment->id}", ['body' => 'Hijacked'])
        ->assertNotFound();
});
