<?php

use App\Models\BoardVisit;
use App\Models\User;
use App\Models\UserFavoriteItem;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Database\Seeders\RolePermissionSeeder;

/**
 * Private boards (the lock icon in the sidebar) follow monday.com: only the creator, the
 * owner, the people added to the board and account admins see them in the sidebar tree,
 * Favorites and Recent. Main and shareable boards stay visible to every workspace member.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->creator = User::factory()->create();
    $this->member = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->workspace->users()->attach($this->creator->id, ['role' => 'owner']);
    $this->workspace->users()->attach($this->member->id, ['role' => 'member']);

    $this->folder = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_GROUP,
        'parent_id' => null,
        'label' => 'Team folder',
    ]);
    $this->main_board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        'label' => 'Main board',
        'board_type' => WorkspaceNavigationItem::BOARD_TYPE_MAIN,
        'created_by_id' => $this->creator->id,
    ]);
    $this->shareable_board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        'label' => 'Shareable board',
        'board_type' => WorkspaceNavigationItem::BOARD_TYPE_SHAREABLE,
        'created_by_id' => $this->creator->id,
    ]);
    $this->private_board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => $this->folder->id,
        'label' => 'Private board',
        'board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
        'created_by_id' => $this->creator->id,
    ]);
});

/**
 * Every node label in a navigation tree payload, depth first.
 *
 * @param  array<int, array<string, mixed>>  $nodes
 * @return array<int, string>
 */
function collectTreeLabels(array $nodes): array
{
    $labels = [];
    foreach ($nodes as $node) {
        $labels[] = $node['label'];
        $labels = [...$labels, ...collectTreeLabels($node['children'] ?? [])];
    }

    return $labels;
}

test('the creator sees the private board with its board type in the tree', function () {
    $response = $this->actingAs($this->creator, 'api')
        ->getJson("/api/workspaces/{$this->workspace->slug}/navigation")
        ->assertOk();

    expect(collectTreeLabels($response->json('data')))
        ->toContain('Main board', 'Shareable board', 'Private board');

    $folder = collect($response->json('data'))->firstWhere('label', 'Team folder');
    expect($folder['children'][0]['board_type'])->toBe('private');
});

test('a member who was not added to a private board does not see it in the tree', function () {
    $response = $this->actingAs($this->member, 'api')
        ->getJson("/api/workspaces/{$this->workspace->slug}/navigation")
        ->assertOk();

    $labels = collectTreeLabels($response->json('data'));
    expect($labels)->toContain('Team folder', 'Main board', 'Shareable board')
        ->not->toContain('Private board');
});

test('a member added to a private board as a collaborator sees it', function () {
    $this->private_board->collaborators()->attach($this->member->id, ['invited_by' => $this->creator->id]);

    $response = $this->actingAs($this->member, 'api')
        ->getJson("/api/workspaces/{$this->workspace->slug}/navigation")
        ->assertOk();

    expect(collectTreeLabels($response->json('data')))->toContain('Private board');
});

test('the board owner sees a private board someone else created', function () {
    $this->private_board->update(['owner_id' => $this->member->id]);

    $response = $this->actingAs($this->member, 'api')
        ->getJson("/api/workspaces/{$this->workspace->slug}/navigation")
        ->assertOk();

    expect(collectTreeLabels($response->json('data')))->toContain('Private board');
});

test('an account admin sees every private board', function () {
    $this->member->assignRole('admin');

    $response = $this->actingAs($this->member, 'api')
        ->getJson("/api/workspaces/{$this->workspace->slug}/navigation")
        ->assertOk();

    expect(collectTreeLabels($response->json('data')))->toContain('Private board');
});

test('switching a board to private hides it from members right away', function () {
    $this->actingAs($this->creator, 'api')
        ->patchJson("/api/workspaces/{$this->workspace->slug}/navigation/{$this->main_board->id}", [
            'board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
        ])
        ->assertOk()
        ->assertJsonPath('item.board_type', 'private');

    $response = $this->actingAs($this->member, 'api')
        ->getJson("/api/workspaces/{$this->workspace->slug}/navigation")
        ->assertOk();

    expect(collectTreeLabels($response->json('data')))->not->toContain('Main board');
});

test('favorites and recent boards drop private boards the user can no longer see', function () {
    foreach ([$this->main_board, $this->private_board] as $position => $board) {
        UserFavoriteItem::create(['user_id' => $this->member->id, 'navigation_item_id' => $board->id, 'position' => $position]);
        BoardVisit::create(['user_id' => $this->member->id, 'board_id' => $board->id, 'visited_at' => now()]);
    }

    $this->actingAs($this->member, 'api')->getJson('/api/favorites')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'Main board');

    $this->actingAs($this->member, 'api')->getJson('/api/home/recent-boards')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'Main board');
});
