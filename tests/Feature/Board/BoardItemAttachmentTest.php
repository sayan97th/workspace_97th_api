<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createAttachmentTestItem(): BoardItem
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

test('a file can be attached directly to an item without creating a comment', function () {
    Storage::fake('public');
    $item = createAttachmentTestItem();
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/attachments",
        ['files' => [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')]]
    );

    $response->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.file_name', 'notes.pdf');

    $this->assertDatabaseHas('board_item_attachments', ['item_id' => $item->id, 'file_name' => 'notes.pdf']);
    $this->assertDatabaseCount('board_item_comments', 0);

    $attachment = \App\Models\BoardItemAttachment::firstOrFail();
    Storage::disk('public')->assertExists($attachment->file_path);
});

test('an item requires at least one file to attach', function () {
    $item = createAttachmentTestItem();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$item->board_id}/items/{$item->id}/attachments", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('files');
});

test('an item\'s attachments can be listed', function () {
    Storage::fake('public');
    $item = createAttachmentTestItem();
    $item->attachments()->create([
        'file_name' => 'a.pdf', 'file_path' => "board-item-attachments/{$item->id}/a.pdf",
        'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10,
    ]);
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$item->board_id}/items/{$item->id}/attachments")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('an attachment can be deleted, removing its file from storage', function () {
    Storage::fake('public');
    $item = createAttachmentTestItem();
    $path = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')
        ->storeAs("board-item-attachments/{$item->id}", 'notes.pdf', 'public');
    $attachment = $item->attachments()->create([
        'file_name' => 'notes.pdf', 'file_path' => $path,
        'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 100,
    ]);
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->deleteJson("/api/boards/{$item->board_id}/items/{$item->id}/attachments/{$attachment->id}")
        ->assertOk();

    $this->assertDatabaseMissing('board_item_attachments', ['id' => $attachment->id]);
    Storage::disk('public')->assertMissing($path);
});

test('an attachment belonging to a different item is not reachable', function () {
    $item = createAttachmentTestItem();
    $other_item = createAttachmentTestItem();
    $attachment = $other_item->attachments()->create([
        'file_name' => 'not-yours.pdf', 'file_path' => "board-item-attachments/{$other_item->id}/not-yours.pdf",
        'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10,
    ]);
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->deleteJson("/api/boards/{$item->board_id}/items/{$item->id}/attachments/{$attachment->id}")
        ->assertNotFound();
});

test('the item index attachment count sums direct attachments and comment attachments', function () {
    Storage::fake('public');
    $item = createAttachmentTestItem();
    $item->attachments()->create([
        'file_name' => 'a.pdf', 'file_path' => "board-item-attachments/{$item->id}/a.pdf",
        'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10,
    ]);
    $comment = $item->comments()->create(['body' => 'With a file']);
    $comment->attachments()->create([
        'file_name' => 'b.pdf', 'file_path' => "board-comment-attachments/{$item->id}/b.pdf",
        'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10,
    ]);
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->getJson("/api/boards/{$item->board_id}/items");
    $row = collect($response->json('data'))->firstWhere('id', $item->id);
    expect($row['attachment_count'])->toBe(2);
});
