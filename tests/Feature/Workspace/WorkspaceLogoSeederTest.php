<?php

use App\Models\Workspace;
use Database\Seeders\WorkspaceLogoSeeder;
use Illuminate\Support\Facades\Storage;

test('the company workspace gets the official logo and other workspaces are left alone', function () {
    Storage::fake(config('filesystems.app_disk'));

    $company_workspace = Workspace::factory()->create(['mono' => '97']);
    $other_workspace = Workspace::factory()->create(['mono' => 'B']);

    $this->seed(WorkspaceLogoSeeder::class);

    $company_workspace->refresh();
    expect($company_workspace->avatar_path)->toEndWith('.png');
    Storage::disk(config('filesystems.app_disk'))->assertExists($company_workspace->avatar_path);
    Storage::disk(config('filesystems.app_disk'))->assertExists($company_workspace->avatar_thumbnail_path);
    expect($other_workspace->refresh()->avatar_path)->toBeNull();
});

test('running it again keeps an avatar that is already set', function () {
    Storage::fake(config('filesystems.app_disk'));

    $workspace = Workspace::factory()->create(['mono' => '97', 'avatar_path' => 'workspace-avatars/custom.png']);

    $this->seed(WorkspaceLogoSeeder::class);

    expect($workspace->refresh()->avatar_path)->toBe('workspace-avatars/custom.png');
});
