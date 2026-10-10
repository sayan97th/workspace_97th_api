<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createWorkspaceWithMember(string $role): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => $role]);

    return [$user, $workspace];
}

test('the owner can upload a cover image, stored as a webp on the configured disk', function () {
    Storage::fake(config('filesystems.app_disk'));
    [$owner, $workspace] = createWorkspaceWithMember('owner');

    $response = $this->actingAs($owner, 'api')->postJson("/api/workspaces/{$workspace->slug}/cover", [
        'file' => UploadedFile::fake()->image('cover.jpg', 1600, 400),
        'cover_position_y' => 30,
    ]);

    $response->assertOk()
        ->assertJsonPath('workspace.cover_position_y', 30)
        ->assertJsonPath('workspace.cover_url', fn ($url) => is_string($url) && str_ends_with($url, '.webp'));

    $workspace->refresh();
    expect($workspace->cover_path)->toEndWith('.webp');
    Storage::disk(config('filesystems.app_disk'))->assertExists($workspace->cover_path);
});

test('uploading a new cover removes the previous file', function () {
    Storage::fake(config('filesystems.app_disk'));
    [$owner, $workspace] = createWorkspaceWithMember('owner');

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/cover", ['file' => UploadedFile::fake()->image('first.png', 1200, 300)])
        ->assertOk();
    $first_cover_path = $workspace->refresh()->cover_path;

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/cover", ['file' => UploadedFile::fake()->image('second.png', 1200, 300)])
        ->assertOk();

    Storage::disk(config('filesystems.app_disk'))->assertMissing($first_cover_path);
    Storage::disk(config('filesystems.app_disk'))->assertExists($workspace->refresh()->cover_path);
});

test('the owner can reposition the cover', function () {
    Storage::fake(config('filesystems.app_disk'));
    [$owner, $workspace] = createWorkspaceWithMember('owner');

    $this->actingAs($owner, 'api')
        ->patchJson("/api/workspaces/{$workspace->slug}/cover", ['cover_position_y' => 80])
        ->assertOk()
        ->assertJsonPath('workspace.cover_position_y', 80);

    $this->actingAs($owner, 'api')
        ->patchJson("/api/workspaces/{$workspace->slug}/cover", ['cover_position_y' => 140])
        ->assertStatus(422);
});

test('the owner can remove the cover, deleting its file', function () {
    Storage::fake(config('filesystems.app_disk'));
    [$owner, $workspace] = createWorkspaceWithMember('owner');

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/cover", ['file' => UploadedFile::fake()->image('cover.png', 1200, 300)])
        ->assertOk();
    $cover_path = $workspace->refresh()->cover_path;

    $this->actingAs($owner, 'api')
        ->deleteJson("/api/workspaces/{$workspace->slug}/cover")
        ->assertOk()
        ->assertJsonPath('workspace.cover_url', null);

    expect($workspace->refresh()->cover_path)->toBeNull();
    Storage::disk(config('filesystems.app_disk'))->assertMissing($cover_path);
});

test('a cover that is too small is rejected', function () {
    Storage::fake(config('filesystems.app_disk'));
    [$owner, $workspace] = createWorkspaceWithMember('owner');

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/cover", ['file' => UploadedFile::fake()->image('tiny.png', 400, 100)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');
});

test('a non-owner member cannot change the cover', function () {
    Storage::fake(config('filesystems.app_disk'));
    [$member, $workspace] = createWorkspaceWithMember('member');

    $this->actingAs($member, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/cover", ['file' => UploadedFile::fake()->image('cover.png', 1200, 300)])
        ->assertStatus(403);

    $this->actingAs($member, 'api')
        ->deleteJson("/api/workspaces/{$workspace->slug}/cover")
        ->assertStatus(403);

    expect($workspace->refresh()->cover_path)->toBeNull();
});
