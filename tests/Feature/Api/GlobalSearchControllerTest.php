<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Database\Seeders\RolePermissionSeeder;

function createSearchBoard(string $label, array $attributes = []): WorkspaceNavigationItem
{
    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'label' => $label,
        'view_key' => null,
        ...$attributes,
    ]);
}

function createSearchItem(WorkspaceNavigationItem $board, string $name, array $attributes = []): BoardItem
{
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);

    return BoardItem::factory()->create([
        'board_id' => $board->id,
        'group_id' => $group->id,
        'name' => $name,
        ...$attributes,
    ]);
}

test('guests cannot search', function () {
    $this->getJson('/api/search?q=roadmap')->assertUnauthorized();
});

test('the search term is required and must have at least two characters', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->getJson('/api/search')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->actingAs($user, 'api')->getJson('/api/search?q=a')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->actingAs($user, 'api')->getJson('/api/search?q=%20%20%20')->assertUnprocessable()->assertJsonValidationErrors('q');
});

test('search returns matching workspaces, boards and items grouped by kind', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['name' => 'Roadmap Studio']);
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'label' => 'Product Roadmap',
        'view_key' => null,
    ]);
    $item = createSearchItem($board, 'Draft roadmap review');
    createSearchBoard('Unrelated board');

    $response = $this->actingAs($user, 'api')->getJson('/api/search?q=roadmap');

    $response->assertOk()
        ->assertJsonPath('meta.query', 'roadmap')
        ->assertJsonPath('data.workspaces.0.name', 'Roadmap Studio')
        ->assertJsonPath('data.boards.0.id', $board->id)
        ->assertJsonPath('data.boards.0.workspace.id', $workspace->id)
        ->assertJsonPath('data.items.0.id', $item->id)
        ->assertJsonPath('data.items.0.board.id', $board->id)
        ->assertJsonPath('data.items.0.view_id', $item->group->board_view_id);
    expect($response->json('data.boards'))->toHaveCount(1);
});

test('search is case insensitive and ranks prefix matches first', function () {
    $user = User::factory()->create();
    createSearchBoard('Weekly Launch Plan');
    $prefix_match = createSearchBoard('Launch checklist');

    $response = $this->actingAs($user, 'api')->getJson('/api/search?q=LAUNCH');

    $response->assertOk();
    expect(collect($response->json('data.boards'))->pluck('label')->all())
        ->toBe(['Launch checklist', 'Weekly Launch Plan'])
        ->and($response->json('data.boards.0.id'))->toBe($prefix_match->id);
});

test('like wildcards in the term match literally', function () {
    $user = User::factory()->create();
    $literal = createSearchBoard('100% done');
    createSearchBoard('100 things');

    $response = $this->actingAs($user, 'api')->getJson('/api/search?q='.urlencode('100%'));

    $response->assertOk();
    expect(collect($response->json('data.boards'))->pluck('id')->all())->toBe([$literal->id]);
});

test('the limit caps each group', function () {
    $user = User::factory()->create();
    foreach (range(1, 4) as $number) {
        createSearchBoard("Sprint board {$number}");
    }

    $response = $this->actingAs($user, 'api')->getJson('/api/search?q=sprint&limit=2');

    $response->assertOk();
    expect($response->json('data.boards'))->toHaveCount(2);

    $this->actingAs($user, 'api')->getJson('/api/search?q=sprint&limit=99')->assertUnprocessable();
});

test('archived boards, the manage workspace leaf and folders never appear', function () {
    $user = User::factory()->create();
    createSearchBoard('Archived plan', ['is_archived' => true]);
    createSearchBoard('Manage plan', ['view_key' => 'workspace_manage']);
    createSearchBoard('Plan folder', ['type' => WorkspaceNavigationItem::TYPE_GROUP]);
    $visible = createSearchBoard('Visible plan');

    $response = $this->actingAs($user, 'api')->getJson('/api/search?q=plan');

    $response->assertOk();
    expect(collect($response->json('data.boards'))->pluck('id')->all())->toBe([$visible->id]);
});

test('boards of a deleted workspace are hidden along with their items', function () {
    $user = User::factory()->create();
    $board = createSearchBoard('Orphan board');
    createSearchItem($board, 'Orphan item');
    $board->workspace->delete();

    $response = $this->actingAs($user, 'api')->getJson('/api/search?q=orphan');

    $response->assertOk();
    expect($response->json('data.boards'))->toBe([])
        ->and($response->json('data.items'))->toBe([])
        ->and($response->json('data.workspaces'))->toBe([]);
});

test('archived and deleted items are excluded, subitems are flagged', function () {
    $user = User::factory()->create();
    $board = createSearchBoard('Delivery');
    $parent = createSearchItem($board, 'Parent task');
    createSearchItem($board, 'Task archived', ['is_archived' => true]);
    createSearchItem($board, 'Task deleted')->delete();
    $subitem = BoardItem::factory()->create([
        'board_id' => $board->id,
        'group_id' => $parent->group_id,
        'parent_id' => $parent->id,
        'name' => 'Task subitem',
    ]);

    $response = $this->actingAs($user, 'api')->getJson('/api/search?q=task');

    $response->assertOk();
    $items = collect($response->json('data.items'))->keyBy('id');
    expect($items->keys()->sort()->values()->all())->toBe(collect([$parent->id, $subitem->id])->sort()->values()->all())
        ->and($items[$subitem->id]['is_subitem'])->toBeTrue()
        ->and($items[$subitem->id]['parent_name'])->toBe('Parent task')
        ->and($items[$parent->id]['is_subitem'])->toBeFalse();
});

test('a private board and its items are only visible to its creator, owner, collaborators and admins', function () {
    $this->seed(RolePermissionSeeder::class);

    $creator = User::factory()->create();
    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $outsider = User::factory()->create();

    $board = createSearchBoard('Secret budget', [
        'board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
        'created_by_id' => $creator->id,
        'owner_id' => $owner->id,
    ]);
    $board->collaborators()->attach($collaborator->id, ['invited_by' => $creator->id]);
    createSearchItem($board, 'Secret budget line');

    foreach ([$creator, $owner, $collaborator, $admin] as $allowed_user) {
        $response = $this->actingAs($allowed_user, 'api')->getJson('/api/search?q=secret');
        expect($response->json('data.boards'))->toHaveCount(1)
            ->and($response->json('data.items'))->toHaveCount(1);
    }

    $response = $this->actingAs($outsider, 'api')->getJson('/api/search?q=secret');
    $response->assertOk();
    expect($response->json('data.boards'))->toBe([])
        ->and($response->json('data.items'))->toBe([]);
});
