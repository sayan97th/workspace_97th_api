<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Database\Seeders\RolePermissionSeeder;

/**
 * The sidebar row menu's monday.com actions: "Move to workspace", "Save as a template",
 * "Move to template" and creating a board from a saved template.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->owner = User::factory()->create();
    $this->outsider = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->other_workspace = Workspace::factory()->create();
    $this->foreign_workspace = Workspace::factory()->create();
    $this->workspace->users()->attach($this->owner->id, ['role' => 'owner']);
    $this->other_workspace->users()->attach($this->owner->id, ['role' => 'member']);
    $this->workspace->users()->attach($this->outsider->id, ['role' => 'member']);

    $this->folder = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_GROUP,
        'parent_id' => null,
        'label' => 'Marketing',
        'created_by_id' => $this->owner->id,
    ]);
    $this->board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => $this->folder->id,
        'label' => 'Launch plan',
        'view_key' => 'board',
        'created_by_id' => $this->owner->id,
    ]);
});

/**
 * Every node label in a navigation tree payload, depth first.
 *
 * @param  array<int, array<string, mixed>>  $nodes
 * @return array<int, string>
 */
function navMenuTreeLabels(array $nodes): array
{
    $labels = [];
    foreach ($nodes as $node) {
        $labels[] = $node['label'];
        $labels = [...$labels, ...navMenuTreeLabels($node['children'] ?? [])];
    }

    return $labels;
}

test('a folder moves to another workspace root with everything inside it', function () {
    $this->actingAs($this->owner, 'api')
        ->patchJson("/api/workspaces/{$this->workspace->slug}/navigation/{$this->folder->id}/move-workspace", [
            'workspace_id' => $this->other_workspace->id,
        ])
        ->assertOk()
        ->assertJsonPath('workspace.slug', $this->other_workspace->slug);

    expect($this->folder->fresh()->workspace_id)->toBe($this->other_workspace->id)
        ->and($this->folder->fresh()->parent_id)->toBeNull()
        ->and($this->board->fresh()->workspace_id)->toBe($this->other_workspace->id)
        ->and($this->board->fresh()->parent_id)->toBe($this->folder->id);
});

test('moving to a workspace the caller does not belong to is forbidden', function () {
    $this->actingAs($this->owner, 'api')
        ->patchJson("/api/workspaces/{$this->workspace->slug}/navigation/{$this->board->id}/move-workspace", [
            'workspace_id' => $this->foreign_workspace->id,
        ])
        ->assertForbidden();

    expect($this->board->fresh()->workspace_id)->toBe($this->workspace->id);
});

test('only board managers can move a board to another workspace', function () {
    $this->other_workspace->users()->attach($this->outsider->id, ['role' => 'member']);

    $this->actingAs($this->outsider, 'api')
        ->patchJson("/api/workspaces/{$this->workspace->slug}/navigation/{$this->board->id}/move-workspace", [
            'workspace_id' => $this->other_workspace->id,
        ])
        ->assertForbidden();
});

test('save as a template keeps the board and lists a hidden copy as a template', function () {
    $this->actingAs($this->owner, 'api')
        ->postJson("/api/workspaces/{$this->workspace->slug}/navigation/{$this->board->id}/template", ['mode' => 'copy'])
        ->assertCreated()
        ->assertJsonPath('item.label', 'Launch plan')
        ->assertJsonPath('item.is_template', true);

    $tree = $this->actingAs($this->owner, 'api')
        ->getJson("/api/workspaces/{$this->workspace->slug}/navigation")
        ->assertOk()
        ->json('data');
    expect(array_count_values(navMenuTreeLabels($tree))['Launch plan'])->toBe(1);

    $this->actingAs($this->owner, 'api')
        ->getJson("/api/workspaces/{$this->workspace->slug}/navigation/templates")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'Launch plan');
});

test('move to template takes the board out of the tree', function () {
    $this->actingAs($this->owner, 'api')
        ->postJson("/api/workspaces/{$this->workspace->slug}/navigation/{$this->board->id}/template", ['mode' => 'move'])
        ->assertOk();

    $tree = $this->actingAs($this->owner, 'api')
        ->getJson("/api/workspaces/{$this->workspace->slug}/navigation")
        ->json('data');

    expect(navMenuTreeLabels($tree))->not->toContain('Launch plan')
        ->and(WorkspaceNavigationItem::query()->find($this->board->id))->toBeNull()
        ->and(WorkspaceNavigationItem::templates()->find($this->board->id))->not->toBeNull();
});

test('folders cannot become templates', function () {
    $this->actingAs($this->owner, 'api')
        ->postJson("/api/workspaces/{$this->workspace->slug}/navigation/{$this->folder->id}/template", ['mode' => 'copy'])
        ->assertStatus(422);
});

test('a template creates a new board in the chosen folder and stays available', function () {
    $this->board->update(['is_template' => true]);

    $response = $this->actingAs($this->owner, 'api')
        ->postJson("/api/workspaces/{$this->workspace->slug}/navigation/templates/{$this->board->id}/use", [
            'label' => 'Q4 launch',
            'parent_id' => $this->folder->id,
        ])
        ->assertCreated()
        ->assertJsonPath('item.label', 'Q4 launch')
        ->assertJsonPath('item.parent_id', $this->folder->id)
        ->assertJsonPath('item.is_template', false);

    expect(WorkspaceNavigationItem::query()->find($response->json('item.id')))->not->toBeNull()
        ->and(WorkspaceNavigationItem::templates()->count())->toBe(1);
});

test('a template can be deleted by its creator', function () {
    $this->board->update(['is_template' => true]);

    $this->actingAs($this->owner, 'api')
        ->deleteJson("/api/workspaces/{$this->workspace->slug}/navigation/templates/{$this->board->id}")
        ->assertOk();

    expect(WorkspaceNavigationItem::templates()->count())->toBe(0);
});
