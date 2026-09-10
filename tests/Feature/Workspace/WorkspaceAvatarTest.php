<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('the owner can upload a workspace avatar and a thumbnail is generated on the configured disk', function () {
    Storage::fake(config('filesystems.app_disk'));

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $file = UploadedFile::fake()->image('avatar.png', 400, 400);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/avatar", ['file' => $file]);

    $response->assertOk();

    $workspace->refresh();
    expect($workspace->avatar_path)->not->toBeNull();
    expect($workspace->avatar_thumbnail_path)->not->toBeNull();

    Storage::disk(config('filesystems.app_disk'))->assertExists($workspace->avatar_path);
    Storage::disk(config('filesystems.app_disk'))->assertExists($workspace->avatar_thumbnail_path);
});

test('uploading a new avatar replaces and removes the previous files from the configured disk', function () {
    Storage::fake(config('filesystems.app_disk'));

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/avatar", ['file' => UploadedFile::fake()->image('first.png', 400, 400)])
        ->assertOk();

    $workspace->refresh();
    $first_avatar_path = $workspace->avatar_path;
    $first_thumbnail_path = $workspace->avatar_thumbnail_path;

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/avatar", ['file' => UploadedFile::fake()->image('second.png', 400, 400)])
        ->assertOk();

    $workspace->refresh();

    Storage::disk(config('filesystems.app_disk'))->assertMissing($first_avatar_path);
    Storage::disk(config('filesystems.app_disk'))->assertMissing($first_thumbnail_path);
    Storage::disk(config('filesystems.app_disk'))->assertExists($workspace->avatar_path);
    Storage::disk(config('filesystems.app_disk'))->assertExists($workspace->avatar_thumbnail_path);
});

test('the owner can remove the workspace avatar, deleting its files from the configured disk', function () {
    Storage::fake(config('filesystems.app_disk'));

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/avatar", ['file' => UploadedFile::fake()->image('avatar.png', 400, 400)])
        ->assertOk();

    $workspace->refresh();
    $avatar_path = $workspace->avatar_path;
    $thumbnail_path = $workspace->avatar_thumbnail_path;

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/workspaces/{$workspace->slug}/avatar");

    $response->assertOk();

    $workspace->refresh();
    expect($workspace->avatar_path)->toBeNull();
    expect($workspace->avatar_thumbnail_path)->toBeNull();

    Storage::disk(config('filesystems.app_disk'))->assertMissing($avatar_path);
    Storage::disk(config('filesystems.app_disk'))->assertMissing($thumbnail_path);
});

test('a non-owner member cannot upload a workspace avatar', function () {
    Storage::fake(config('filesystems.app_disk'));

    $member = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($member->id, ['role' => 'member']);

    $response = $this->actingAs($member, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/avatar", ['file' => UploadedFile::fake()->image('avatar.png', 400, 400)]);

    $response->assertStatus(403);
    expect($workspace->refresh()->avatar_path)->toBeNull();
});
