<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * Sidebar round: personal layout preferences, folder colors, favorite
 * workspaces, the multi-select bulk bar and "Sort A to Z".
 */
function createSidebarNode(Workspace $workspace, array $attributes = []): WorkspaceNavigationItem
{
    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        ...$attributes,
    ]);
}

// ── Sidebar preferences ────────────────────────────────────────────────────

test('the profile always returns a complete default sidebar layout', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('data.sidebar_preferences.sections.0', ['key' => 'home', 'is_visible' => true])
        ->assertJsonCount(4, 'data.sidebar_preferences.sections')
        ->assertJsonPath('data.sidebar_preferences.collapsed_sections', []);
});

test('section order, visibility and collapse state are saved without touching the width', function () {
    $user = User::factory()->create(['sidebar_width' => 300]);

    $this->actingAs($user, 'api')->patchJson('/api/profile/sidebar', [
        'sections' => [
            ['key' => 'favorites', 'is_visible' => true],
            ['key' => 'home', 'is_visible' => false],
        ],
        'collapsed_sections' => ['recent', 'favorites_workspace:12'],
    ])
        ->assertOk()
        ->assertJsonPath('user.sidebar_width', 300)
        ->assertJsonPath('user.sidebar_preferences.sections.0.key', 'favorites')
        ->assertJsonPath('user.sidebar_preferences.sections.1', ['key' => 'home', 'is_visible' => false])
        // Sections left out are appended, still visible.
        ->assertJsonPath('user.sidebar_preferences.sections.2.key', 'my_work')
        ->assertJsonPath('user.sidebar_preferences.collapsed_sections', ['recent', 'favorites_workspace:12']);

    // Saving only the collapse state keeps the saved order.
    $this->actingAs($user, 'api')->patchJson('/api/profile/sidebar', ['collapsed_sections' => []])
        ->assertOk()
        ->assertJsonPath('user.sidebar_preferences.sections.0.key', 'favorites')
        ->assertJsonPath('user.sidebar_preferences.collapsed_sections', []);
});

test('unknown sidebar sections and an empty payload are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->patchJson('/api/profile/sidebar', ['sections' => [['key' => 'apps', 'is_visible' => true]]])
        ->assertUnprocessable();
    $this->actingAs($user, 'api')->patchJson('/api/profile/sidebar', ['collapsed_sections' => ['<script>']])
        ->assertUnprocessable();
    $this->actingAs($user, 'api')->patchJson('/api/profile/sidebar', [])
        ->assertUnprocessable();
});

// ── Folder colors ──────────────────────────────────────────────────────────

test('a folder color is saved, validated and copied on duplicate', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $folder = createSidebarNode($workspace, ['type' => WorkspaceNavigationItem::TYPE_GROUP, 'label' => 'Clients']);

    $this->actingAs($user, 'api')
        ->patchJson("/api/workspaces/{$workspace->slug}/navigation/{$folder->id}", ['color' => '#00c875'])
        ->assertOk()
        ->assertJsonPath('item.color', '#00c875');

    $this->actingAs($user, 'api')
        ->patchJson("/api/workspaces/{$workspace->slug}/navigation/{$folder->id}", ['color' => 'green'])
        ->assertUnprocessable();

    $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/navigation/{$folder->id}/duplicate")
        ->assertCreated()
        ->assertJsonPath('item.color', '#00c875');
});

// ── Favorite workspaces ────────────────────────────────────────────────────

test('a whole workspace can be starred and unstarred', function () {
    $user = User::factory()->create();
    $other_user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $this->actingAs($user, 'api')->putJson("/api/favorites/workspaces/{$workspace->slug}")->assertOk();
    // Starring twice keeps a single row.
    $this->actingAs($user, 'api')->putJson("/api/favorites/workspaces/{$workspace->slug}")->assertOk();

    $this->actingAs($user, 'api')->getJson('/api/favorites')
        ->assertJsonCount(1, 'workspaces')
        ->assertJsonPath('workspaces.0.id', $workspace->id);
    $this->actingAs($other_user, 'api')->getJson('/api/favorites')->assertJsonCount(0, 'workspaces');

    $this->actingAs($user, 'api')->deleteJson("/api/favorites/workspaces/{$workspace->slug}")->assertOk();
    $this->actingAs($user, 'api')->getJson('/api/favorites')->assertJsonCount(0, 'workspaces');
});

// ── Bulk actions ───────────────────────────────────────────────────────────

test('bulk move puts every selected item into the target folder', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $folder = createSidebarNode($workspace, ['type' => WorkspaceNavigationItem::TYPE_GROUP]);
    $first = createSidebarNode($workspace);
    $second = createSidebarNode($workspace);

    $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/navigation/bulk", [
            'action' => 'move',
            'item_ids' => [$first->id, $second->id],
            'parent_id' => $folder->id,
        ])
        ->assertOk();

    expect($first->fresh()->parent_id)->toBe($folder->id)
        ->and($second->fresh()->parent_id)->toBe($folder->id);
});

test('bulk move refuses to put a folder inside itself', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $folder = createSidebarNode($workspace, ['type' => WorkspaceNavigationItem::TYPE_GROUP]);
    $child_folder = createSidebarNode($workspace, ['type' => WorkspaceNavigationItem::TYPE_GROUP, 'parent_id' => $folder->id]);

    $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/navigation/bulk", [
            'action' => 'move',
            'item_ids' => [$folder->id],
            'parent_id' => $child_folder->id,
        ])
        ->assertUnprocessable();
});

test('bulk archive hides the selection from the navigation tree', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $board = createSidebarNode($workspace, ['created_by_id' => $owner->id]);
    $kept = createSidebarNode($workspace, ['created_by_id' => $owner->id]);

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/navigation/bulk", ['action' => 'archive', 'item_ids' => [$board->id]])
        ->assertOk();

    expect($board->fresh()->is_archived)->toBeTrue();
    $this->actingAs($owner, 'api')->getJson("/api/workspaces/{$workspace->slug}/navigation")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $kept->id);
});

test('bulk archive is all or nothing when one board is not manageable', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $own_board = createSidebarNode($workspace, ['created_by_id' => $user->id]);
    $foreign_board = createSidebarNode($workspace, ['created_by_id' => User::factory()->create()->id]);

    $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/navigation/bulk", ['action' => 'archive', 'item_ids' => [$own_board->id, $foreign_board->id]])
        ->assertForbidden();

    expect($own_board->fresh()->is_archived)->toBeFalse();
});

test('bulk delete soft deletes a folder with its content, skipping selected descendants', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $folder = createSidebarNode($workspace, ['type' => WorkspaceNavigationItem::TYPE_GROUP]);
    $child = createSidebarNode($workspace, ['parent_id' => $folder->id]);

    $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/navigation/bulk", ['action' => 'delete', 'item_ids' => [$folder->id, $child->id]])
        ->assertOk()
        ->assertJsonPath('item_ids', [$folder->id]);

    expect(WorkspaceNavigationItem::find($folder->id))->toBeNull()
        ->and(WorkspaceNavigationItem::find($child->id))->toBeNull()
        ->and(WorkspaceNavigationItem::withTrashed()->find($child->id))->not->toBeNull();
});

// ── Sort A to Z ────────────────────────────────────────────────────────────

test('sort puts folders first, then boards, naturally and recursively', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $board_ten = createSidebarNode($workspace, ['label' => 'Board 10', 'position' => 0]);
    $board_two = createSidebarNode($workspace, ['label' => 'board 2', 'position' => 1]);
    $folder = createSidebarNode($workspace, ['type' => WorkspaceNavigationItem::TYPE_GROUP, 'label' => 'Zeta', 'position' => 2]);
    $nested_b = createSidebarNode($workspace, ['label' => 'Beta', 'parent_id' => $folder->id, 'position' => 0]);
    $nested_a = createSidebarNode($workspace, ['label' => 'Alpha', 'parent_id' => $folder->id, 'position' => 1]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/workspaces/{$workspace->slug}/navigation/sort", ['parent_id' => null, 'recursive' => true])
        ->assertOk();

    expect($folder->fresh()->position)->toBe(0)
        ->and($board_two->fresh()->position)->toBe(1)
        ->and($board_ten->fresh()->position)->toBe(2)
        ->and($nested_a->fresh()->position)->toBe(0)
        ->and($nested_b->fresh()->position)->toBe(1);
});
