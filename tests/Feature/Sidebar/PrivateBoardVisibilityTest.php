<?php

use App\Models\BoardVisit;
use App\Models\User;
use App\Models\UserFavoriteItem;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Database\Seeders\RolePermissionSeeder;

/**
 * Private boards (the lock icon in the sidebar) follow monday.com: only the creator, the
 * owner and the people added to the board see or open them, in the sidebar tree, Favorites,
 * Recent, the Content list and by direct link. Account admins and workspace owners get no
 * bypass. Main and shareable boards stay visible to every workspace member.
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

test('an account admin who was not added does not see someone else\'s private board', function () {
    $this->member->assignRole('admin');

    $response = $this->actingAs($this->member, 'api')
        ->getJson("/api/workspaces/{$this->workspace->slug}/navigation")
        ->assertOk();

    expect(collectTreeLabels($response->json('data')))->not->toContain('Private board');
});

test('a workspace owner who did not create the private board does not see it', function () {
    $other_owner = User::factory()->create();
    $this->workspace->users()->attach($other_owner->id, ['role' => 'owner']);

    $response = $this->actingAs($other_owner, 'api')
        ->getJson("/api/workspaces/{$this->workspace->slug}/navigation")
        ->assertOk();

    expect(collectTreeLabels($response->json('data')))->not->toContain('Private board');
});

test('opening a private board by direct link is blocked for people who were not added', function () {
    $this->member->assignRole('admin');

    $this->actingAs($this->member, 'api')
        ->getJson("/api/boards/{$this->private_board->id}")
        ->assertForbidden()
        ->assertJsonPath('code', 'board_private');

    $this->actingAs($this->member, 'api')
        ->getJson("/api/boards/{$this->private_board->id}/columns")
        ->assertForbidden();

    $this->actingAs($this->member, 'api')
        ->patchJson("/api/workspaces/{$this->workspace->slug}/navigation/{$this->private_board->id}", ['label' => 'Renamed'])
        ->assertForbidden();

    expect($this->private_board->fresh()->label)->toBe('Private board');
});

test('the creator and invited people can open a private board by direct link', function () {
    $this->actingAs($this->creator, 'api')
        ->getJson("/api/boards/{$this->private_board->id}")
        ->assertOk();

    $this->private_board->collaborators()->attach($this->member->id, ['invited_by' => $this->creator->id]);

    $this->actingAs($this->member, 'api')
        ->getJson("/api/boards/{$this->private_board->id}")
        ->assertOk();
});

test('the Content list and its creators facet skip private boards the user cannot see', function () {
    $this->member->assignRole('admin');

    $response = $this->actingAs($this->member, 'api')->getJson('/api/content?per_page=100')->assertOk();
    $labels = collect($response->json('data'))->pluck('label');
    expect($labels)->toContain('Main board')->not->toContain('Private board');

    $creators = collect($this->actingAs($this->member, 'api')->getJson('/api/content/creators')->json('data'));
    expect($creators->firstWhere('id', $this->creator->id)['content_count'])->toBe(2);
});

test('only the creator or owner can switch a board to private', function () {
    $this->member->assignRole('admin');

    $this->actingAs($this->member, 'api')
        ->patchJson("/api/workspaces/{$this->workspace->slug}/navigation/{$this->main_board->id}", [
            'board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
        ])
        ->assertForbidden();

    expect($this->main_board->fresh()->board_type)->toBe(WorkspaceNavigationItem::BOARD_TYPE_MAIN);

    $this->actingAs($this->member, 'api')
        ->getJson("/api/boards/{$this->main_board->id}")
        ->assertJsonPath('can_make_private', false);

    $this->main_board->update(['owner_id' => $this->member->id]);

    $this->actingAs($this->member, 'api')
        ->patchJson("/api/workspaces/{$this->workspace->slug}/navigation/{$this->main_board->id}", [
            'board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
        ])
        ->assertOk()
        ->assertJsonPath('item.can_make_private', true);
});

test('sidebar bulk actions skip private boards the user cannot see', function () {
    $this->member->assignRole('admin');

    $this->actingAs($this->member, 'api')
        ->postJson("/api/workspaces/{$this->workspace->slug}/navigation/bulk", [
            'action' => 'archive',
            'item_ids' => [$this->private_board->id],
        ]);

    expect($this->private_board->fresh()->is_archived)->toBeFalse();
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

test('a board created as private from the sidebar is only visible to its creator', function () {
    $response = $this->actingAs($this->creator, 'api')
        ->postJson("/api/workspaces/{$this->workspace->slug}/navigation", [
            'type' => WorkspaceNavigationItem::TYPE_LEAF,
            'label' => 'Created private',
            'view_key' => 'board',
            'board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
        ])
        ->assertCreated()
        ->assertJsonPath('item.board_type', WorkspaceNavigationItem::BOARD_TYPE_PRIVATE)
        ->assertJsonPath('item.can_make_private', true);

    $board_id = $response->json('item.id');
    expect(WorkspaceNavigationItem::find($board_id)->created_by_id)->toBe($this->creator->id);

    $creator_tree = $this->actingAs($this->creator, 'api')
        ->getJson("/api/workspaces/{$this->workspace->slug}/navigation")
        ->assertOk();
    expect(collectTreeLabels($creator_tree->json('data')))->toContain('Created private');

    $member_tree = $this->actingAs($this->member, 'api')
        ->getJson("/api/workspaces/{$this->workspace->slug}/navigation")
        ->assertOk();
    expect(collectTreeLabels($member_tree->json('data')))->not->toContain('Created private');

    $this->actingAs($this->member, 'api')->getJson("/api/boards/{$board_id}")->assertForbidden();
});

test('a new board defaults to main when no privacy is sent', function () {
    $this->actingAs($this->creator, 'api')
        ->postJson("/api/workspaces/{$this->workspace->slug}/navigation", [
            'type' => WorkspaceNavigationItem::TYPE_LEAF,
            'label' => 'Created main',
            'view_key' => 'board',
        ])
        ->assertCreated()
        ->assertJsonPath('item.board_type', WorkspaceNavigationItem::BOARD_TYPE_MAIN);
});

test('a folder is never stored as private', function () {
    $this->actingAs($this->creator, 'api')
        ->postJson("/api/workspaces/{$this->workspace->slug}/navigation", [
            'type' => WorkspaceNavigationItem::TYPE_GROUP,
            'label' => 'Created folder',
            'board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
        ])
        ->assertCreated()
        ->assertJsonPath('item.board_type', WorkspaceNavigationItem::BOARD_TYPE_MAIN);
});

test('an unknown privacy is rejected when creating a board', function () {
    $this->actingAs($this->creator, 'api')
        ->postJson("/api/workspaces/{$this->workspace->slug}/navigation", [
            'type' => WorkspaceNavigationItem::TYPE_LEAF,
            'label' => 'Bad privacy',
            'view_key' => 'board',
            'board_type' => 'secret',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('board_type');
});
