<?php

use App\Models\BoardComment;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createManageAttachmentsItem(): BoardItem
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

/** Stores a fake video on the public disk and records it on the update. */
function attachStoredVideo(BoardItemComment|BoardComment $comment, User $author, string $file_name, bool $keep_file = true): array
{
    $path = "board-comment-attachments/test/{$file_name}";
    if ($keep_file) {
        Storage::disk('public')->put($path, 'video-bytes');
    }

    $attachment = $comment->attachments()->create([
        'uploaded_by_id' => $author->id,
        'file_name' => $file_name,
        'file_path' => $path,
        'extension' => 'webm',
        'mime_type' => 'video/webm',
        'size_bytes' => 11,
    ]);

    return [$attachment, $path];
}

test('the author can delete a video from a posted update and its file is removed', function () {
    Storage::fake('public');
    $item = createManageAttachmentsItem();
    $author = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Here is the walkthrough']);
    [$attachment, $path] = attachStoredVideo($comment, $author, 'walkthrough.webm');

    $this->actingAs($author, 'api')
        ->deleteJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/attachments/{$attachment->id}")
        ->assertOk()
        ->assertJsonCount(0, 'comment.attachments')
        ->assertJsonPath('comment.is_edited', true);

    Storage::disk('public')->assertMissing($path);
    $this->assertDatabaseMissing('board_item_comment_attachments', ['id' => $attachment->id]);
});

test('deleting a video whose file is already gone from storage still succeeds', function () {
    Storage::fake('public');
    $item = createManageAttachmentsItem();
    $author = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Text stays']);
    [$attachment] = attachStoredVideo($comment, $author, 'missing.webm', keep_file: false);

    $this->actingAs($author, 'api')
        ->deleteJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/attachments/{$attachment->id}")
        ->assertOk();

    $this->assertDatabaseMissing('board_item_comment_attachments', ['id' => $attachment->id]);
});

test('the only file of an update without text cannot be deleted on its own', function () {
    Storage::fake('public');
    $item = createManageAttachmentsItem();
    $author = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => '']);
    [$attachment, $path] = attachStoredVideo($comment, $author, 'only.webm');

    $this->actingAs($author, 'api')
        ->deleteJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/attachments/{$attachment->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attachment');

    Storage::disk('public')->assertExists($path);
});

test('the author can replace a video, keeping the attachment id and deleting the old file', function () {
    Storage::fake('public');
    $item = createManageAttachmentsItem();
    $author = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Take one']);
    [$attachment, $old_path] = attachStoredVideo($comment, $author, 'take-one.webm');

    $response = $this->actingAs($author, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/attachments/{$attachment->id}/replace",
        ['file' => UploadedFile::fake()->create('take-two.webm', 512, 'video/webm')],
        ['Accept' => 'application/json']
    );

    $response->assertOk()
        ->assertJsonPath('comment.attachments.0.id', $attachment->id)
        ->assertJsonPath('comment.attachments.0.file_name', 'take-two.webm');

    Storage::disk('public')->assertMissing($old_path);
    Storage::disk('public')->assertExists($attachment->fresh()->file_path);
});

test('someone else cannot delete or replace the files of an update', function () {
    Storage::fake('public');
    $item = createManageAttachmentsItem();
    $author = User::factory()->create();
    $other = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Mine']);
    [$attachment, $path] = attachStoredVideo($comment, $author, 'mine.webm');
    $base = "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/attachments/{$attachment->id}";

    $this->actingAs($other, 'api')->deleteJson($base)->assertForbidden();
    $this->actingAs($other, 'api')->post("{$base}/replace", ['file' => UploadedFile::fake()->create('x.webm', 10, 'video/webm')], ['Accept' => 'application/json'])->assertForbidden();

    Storage::disk('public')->assertExists($path);
});

test('a board discussion update can have its video deleted', function () {
    Storage::fake('public');
    $item = createManageAttachmentsItem();
    $author = User::factory()->create();
    $comment = BoardComment::create(['board_id' => $item->board_id, 'user_id' => $author->id, 'body' => 'Board update']);
    [$attachment, $path] = attachStoredVideo($comment, $author, 'board.webm');

    $this->actingAs($author, 'api')
        ->deleteJson("/api/boards/{$item->board_id}/comments/{$comment->id}/attachments/{$attachment->id}")
        ->assertOk()
        ->assertJsonCount(0, 'comment.attachments');

    Storage::disk('public')->assertMissing($path);
});
