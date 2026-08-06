<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createCoverTestItem(): BoardItem
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return $board->items()->create(['group_id' => $group->id, 'name' => 'Card', 'position' => 0]);
}

test('a cover image can be uploaded and exposes a public url', function () {
    Storage::fake('public');
    $item = createCoverTestItem();
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/cover",
        ['cover' => UploadedFile::fake()->image('cover.jpg')],
        ['Accept' => 'application/json']
    );

    $response->assertOk();
    $item->refresh();
    expect($item->cover_image_path)->not->toBeNull();
    expect($response->json('item.cover_image_url'))->toContain($item->cover_image_path);
    Storage::disk('public')->assertExists($item->cover_image_path);
});

test('uploading a new cover deletes the previous file', function () {
    Storage::fake('public');
    $item = createCoverTestItem();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/cover",
        ['cover' => UploadedFile::fake()->image('first.jpg')],
        ['Accept' => 'application/json']
    );
    $first_path = $item->refresh()->cover_image_path;

    $this->actingAs($user, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/cover",
        ['cover' => UploadedFile::fake()->image('second.jpg')],
        ['Accept' => 'application/json']
    );
    $item->refresh();

    expect($item->cover_image_path)->not->toBe($first_path);
    Storage::disk('public')->assertMissing($first_path);
    Storage::disk('public')->assertExists($item->cover_image_path);
});

test('a cover image can be removed', function () {
    Storage::fake('public');
    $item = createCoverTestItem();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/cover",
        ['cover' => UploadedFile::fake()->image('cover.jpg')],
        ['Accept' => 'application/json']
    );
    $path = $item->refresh()->cover_image_path;

    $response = $this->actingAs($user, 'api')
        ->deleteJson("/api/boards/{$item->board_id}/items/{$item->id}/cover");

    $response->assertOk()->assertJsonPath('item.cover_image_url', null);
    Storage::disk('public')->assertMissing($path);
    expect($item->refresh()->cover_image_path)->toBeNull();
});

test('a non-image file is rejected', function () {
    Storage::fake('public');
    $item = createCoverTestItem();
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/cover",
        ['cover' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')],
        ['Accept' => 'application/json']
    );

    $response->assertUnprocessable()->assertJsonValidationErrors('cover');
});
