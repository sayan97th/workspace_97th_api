<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function attachWorkspace(User $user, Workspace $workspace, string $role = 'member'): void
{
    $workspace->users()->attach($user->id, ['role' => $role]);
}

test('the manage workspace leaf never appears in the content list', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    attachWorkspace($user, $workspace, 'owner');

    WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'view_key' => 'workspace_manage',
        'created_by_id' => $user->id,
    ]);
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'view_key' => 'board',
        'created_by_id' => $user->id,
    ]);

    $response = $this->actingAs($user, 'api')->getJson('/api/content');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$board->id]);
});

test('asset type filter buckets doc/dashboard/workflow view keys and falls back to board', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    attachWorkspace($user, $workspace, 'owner');

    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'view_key' => null]);
    $doc = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'view_key' => 'doc']);
    WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'view_key' => 'dashboard']);

    $response = $this->actingAs($user, 'api')->getJson('/api/content?'.http_build_query([
        'asset_type' => ['doc', 'board'],
    ]));

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$board->id, $doc->id])->sort()->values()->all());
});

test('last modified filter only returns items older than the selected cutoff', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    attachWorkspace($user, $workspace, 'owner');

    $stale = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id]);
    $stale->forceFill(['updated_at' => now()->subYears(3)])->saveQuietly();

    $fresh = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id]);

    $response = $this->actingAs($user, 'api')->getJson('/api/content?'.http_build_query([
        'last_modified' => ['2y'],
    ]));

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$stale->id]);
});

test('membership filter scopes to workspaces where the user has the selected role', function () {
    $user = User::factory()->create();
    $owned_workspace = Workspace::factory()->create();
    $member_workspace = Workspace::factory()->create();
    attachWorkspace($user, $owned_workspace, 'owner');
    attachWorkspace($user, $member_workspace, 'member');

    $owned_item = WorkspaceNavigationItem::factory()->create(['workspace_id' => $owned_workspace->id]);
    WorkspaceNavigationItem::factory()->create(['workspace_id' => $member_workspace->id]);

    $response = $this->actingAs($user, 'api')->getJson('/api/content?'.http_build_query([
        'membership' => ['owner'],
    ]));

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$owned_item->id]);
});

test('created by filter narrows to the selected creators', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $workspace = Workspace::factory()->create();
    attachWorkspace($user, $workspace, 'owner');

    $mine = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by_id' => $user->id,
    ]);
    WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by_id' => $other->id,
    ]);

    $response = $this->actingAs($user, 'api')->getJson('/api/content?'.http_build_query([
        'created_by' => [$user->id],
    ]));

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$mine->id]);
});

test('the creators endpoint returns distinct creators with content counts', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $workspace = Workspace::factory()->create();
    attachWorkspace($user, $workspace, 'owner');

    WorkspaceNavigationItem::factory()->count(2)->create([
        'workspace_id' => $workspace->id,
        'created_by_id' => $user->id,
    ]);
    WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by_id' => $other->id,
    ]);
    WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'view_key' => 'workspace_manage',
        'created_by_id' => $user->id,
    ]);

    $response = $this->actingAs($user, 'api')->getJson('/api/content/creators');

    $response->assertOk()
        ->assertJsonPath('data.0.id', $user->id)
        ->assertJsonPath('data.0.content_count', 2)
        ->assertJsonPath('data.1.id', $other->id)
        ->assertJsonPath('data.1.content_count', 1);
});
