<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createEditAttachmentsBoard(): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();

    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
}

function createEditAttachmentsItem(): BoardItem
{
    $board = createEditAttachmentsBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
}

function attachStoredFile(BoardItemComment $comment, User $author, string $file_name): string
{
    $path = UploadedFile::fake()->create($file_name, 100, 'application/pdf')
        ->storeAs("board-comment-attachments/{$comment->item_id}", $file_name, 'public');

    $comment->attachments()->create([
        'uploaded_by_id' => $author->id,
        'file_name' => $file_name,
        'file_path' => $path,
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
    ]);

    return $path;
}

test('editing an update can add files and remove existing ones in one multipart request', function () {
    Storage::fake('public');
    $item = createEditAttachmentsItem();
    $author = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Original update']);
    $kept_path = attachStoredFile($comment, $author, 'kept.pdf');
    $removed_path = attachStoredFile($comment, $author, 'removed.pdf');
    $removed_id = $comment->attachments()->where('file_name', 'removed.pdf')->value('id');

    $response = $this->actingAs($author, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}",
        [
            '_method' => 'PATCH',
            'body' => 'Edited update',
            'attachments' => [UploadedFile::fake()->image('added.png')],
            'removed_attachment_ids' => [$removed_id],
        ],
        ['Accept' => 'application/json']
    );

    $response->assertOk()
        ->assertJsonPath('comment.body', 'Edited update')
        ->assertJsonPath('comment.is_edited', true)
        ->assertJsonCount(2, 'comment.attachments');

    expect(collect($response->json('comment.attachments'))->pluck('file_name')->sort()->values()->all())
        ->toBe(['added.png', 'kept.pdf']);

    Storage::disk('public')->assertExists($kept_path);
    Storage::disk('public')->assertMissing($removed_path);
    $this->assertDatabaseMissing('board_item_comment_attachments', ['id' => $removed_id]);
});

test('changing only the files of an update marks it as edited without a body revision', function () {
    Storage::fake('public');
    $item = createEditAttachmentsItem();
    $author = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Same text']);

    $this->actingAs($author, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}",
        ['_method' => 'PATCH', 'body' => 'Same text', 'attachments' => [UploadedFile::fake()->image('added.png')]],
        ['Accept' => 'application/json']
    )->assertOk()->assertJsonPath('comment.is_edited', true)->assertJsonCount(1, 'comment.attachments');

    expect($comment->revisions()->count())->toBe(0);
});

test('an update can be edited down to its files only, but not to nothing at all', function () {
    Storage::fake('public');
    $item = createEditAttachmentsItem();
    $author = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Has a file']);
    attachStoredFile($comment, $author, 'notes.pdf');
    $attachment_id = $comment->attachments()->value('id');
    $url = "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}";

    $this->actingAs($author, 'api')
        ->patchJson($url, ['body' => '', 'removed_attachment_ids' => [$attachment_id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');

    $this->assertDatabaseHas('board_item_comment_attachments', ['id' => $attachment_id]);

    $this->actingAs($author, 'api')
        ->patchJson($url, ['body' => ''])
        ->assertOk()
        ->assertJsonPath('comment.body', '')
        ->assertJsonCount(1, 'comment.attachments');
});

test('removing an attachment that belongs to another update is ignored', function () {
    Storage::fake('public');
    $item = createEditAttachmentsItem();
    $author = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Mine']);
    $other_comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Other']);
    $other_path = attachStoredFile($other_comment, $author, 'other.pdf');
    $other_attachment_id = $other_comment->attachments()->value('id');

    $this->actingAs($author, 'api')
        ->patchJson(
            "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}",
            ['body' => 'Mine, edited', 'removed_attachment_ids' => [$other_attachment_id]]
        )
        ->assertOk();

    $this->assertDatabaseHas('board_item_comment_attachments', ['id' => $other_attachment_id]);
    Storage::disk('public')->assertExists($other_path);
});

test('a board discussion update can be edited with new files', function () {
    Storage::fake('public');
    $board = createEditAttachmentsBoard();
    $author = User::factory()->create();
    $comment = $board->comments()->create(['user_id' => $author->id, 'body' => 'Board update']);

    $this->actingAs($author, 'api')->post(
        "/api/boards/{$board->id}/comments/{$comment->id}",
        ['_method' => 'PATCH', 'body' => 'Board update, edited', 'attachments' => [UploadedFile::fake()->image('added.png')]],
        ['Accept' => 'application/json']
    )->assertOk()
        ->assertJsonPath('comment.body', 'Board update, edited')
        ->assertJsonCount(1, 'comment.attachments')
        ->assertJsonPath('comment.attachments.0.file_name', 'added.png');
});

test('line breaks sent as CRLF by a multipart form are stored as LF', function () {
    Storage::fake('public');
    $item = createEditAttachmentsItem();
    $author = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => "First line\n\nSecond line"]);

    $this->actingAs($author, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}",
        ['_method' => 'PATCH', 'body' => "First line\r\n\r\nSecond line, edited"],
        ['Accept' => 'application/json']
    )->assertOk()->assertJsonPath('comment.body', "First line\n\nSecond line, edited");
});
