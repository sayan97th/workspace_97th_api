<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemCommentAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createVideoAttachmentsItem(): BoardItem
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

test('an update can carry a screen recording made in the browser', function () {
    Storage::fake('public');
    $item = createVideoAttachmentsItem();
    $author = User::factory()->create();

    $response = $this->actingAs($author, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments",
        ['attachments' => [UploadedFile::fake()->create('Screen recording.webm', 2048, 'video/webm')]],
        ['Accept' => 'application/json']
    );

    $response->assertCreated()
        ->assertJsonCount(1, 'comment.attachments')
        ->assertJsonPath('comment.attachments.0.file_name', 'Screen recording.webm')
        ->assertJsonPath('comment.attachments.0.mime_type', 'video/webm');

    Storage::disk('public')->assertExists(BoardItemCommentAttachment::firstOrFail()->file_path);
});

test('videos may be larger than the 50 MB cap documents and images keep', function () {
    Storage::fake('public');
    $item = createVideoAttachmentsItem();
    $author = User::factory()->create();

    $this->actingAs($author, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments",
        ['attachments' => [UploadedFile::fake()->create('walkthrough.mp4', 80 * 1024, 'video/mp4')]],
        ['Accept' => 'application/json']
    )->assertCreated();

    $this->actingAs($author, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments",
        ['attachments' => [UploadedFile::fake()->create('huge.pdf', 80 * 1024, 'application/pdf')]],
        ['Accept' => 'application/json']
    )->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
});

test('videos over 500 MB are rejected', function () {
    Storage::fake('public');
    $item = createVideoAttachmentsItem();
    $author = User::factory()->create();

    $this->actingAs($author, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments",
        ['attachments' => [UploadedFile::fake()->create('long.webm', 520 * 1024, 'video/webm')]],
        ['Accept' => 'application/json']
    )->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
});
