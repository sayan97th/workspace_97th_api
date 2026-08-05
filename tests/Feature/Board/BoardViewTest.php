<?php

use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createViewTestBoard(): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();

    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
}

test('a view can be created as a new tab', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views", ['label' => 'By owner'])
        ->assertCreated()
        ->assertJsonPath('view.label', 'By owner')
        ->assertJsonPath('view.is_primary', false);
});

test('saving filters on a view persists and round-trips the exact state', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);

    $filter_state = [
        'search_query' => 'homepage',
        'search_column_ids' => ['1', '2'],
        'selected_person_ids' => [],
        'quick_filter_selections' => [],
        'advanced_filter_rows' => [],
    ];

    $save_response = $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/views/{$view->id}", [
        'filter_state' => $filter_state,
        'row_height' => 'double',
    ]);

    $save_response->assertOk()->assertJsonPath('view.row_height', 'double');

    $reload_response = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/views");
    $saved_view = collect($reload_response->json('data'))->firstWhere('id', $view->id);

    expect($saved_view['filter_state'])->toBe($filter_state);
    expect($saved_view['row_height'])->toBe('double');
});

test('the primary view cannot be deleted', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);

    $this->actingAs($user, 'api')
        ->deleteJson("/api/boards/{$board->id}/views/{$view->id}")
        ->assertStatus(422);

    $this->assertDatabaseHas('board_views', ['id' => $view->id]);
});

test('a view belonging to a different board returns 404', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();
    $other_board = createViewTestBoard();
    $view = BoardView::factory()->create(['board_id' => $other_board->id]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/views/{$view->id}", ['label' => 'Hacked'])
        ->assertNotFound();
});

test('a view can be duplicated', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();
    $view = BoardView::factory()->create([
        'board_id' => $board->id,
        'is_primary' => true,
        'label' => 'Main table',
        'row_height' => 'double',
    ]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$view->id}/duplicate")
        ->assertCreated()
        ->assertJsonPath('view.label', 'Main table (copy)')
        ->assertJsonPath('view.is_primary', false)
        ->assertJsonPath('view.row_height', 'double');

    expect(BoardView::where('board_id', $board->id)->count())->toBe(2);
});

test('pinning a view toggles the pinned flag', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$view->id}/pin")
        ->assertOk()
        ->assertJsonPath('view.pinned', true);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$view->id}/pin")
        ->assertOk()
        ->assertJsonPath('view.pinned', false);
});

test('locking a view records the locking user and blocks edits until unlocked', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$view->id}/lock")
        ->assertOk()
        ->assertJsonPath('view.is_locked', true)
        ->assertJsonPath('view.locked_by_id', $user->id);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/views/{$view->id}", ['label' => 'Blocked'])
        ->assertStatus(423);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$view->id}/duplicate")
        ->assertStatus(423);

    $this->actingAs($user, 'api')
        ->deleteJson("/api/boards/{$board->id}/views/{$view->id}")
        ->assertStatus(423);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$view->id}/lock")
        ->assertOk()
        ->assertJsonPath('view.is_locked', false)
        ->assertJsonPath('view.locked_by_id', null);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/views/{$view->id}", ['label' => 'Unblocked'])
        ->assertOk()
        ->assertJsonPath('view.label', 'Unblocked');
});

test('pinning a locked view is still allowed', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false, 'is_locked' => true]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$view->id}/pin")
        ->assertOk()
        ->assertJsonPath('view.pinned', true);
});

test('a personal view order is saved per user and overwritten on repeat saves', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();
    $primary = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $second = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);
    $third = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);

    $this->actingAs($user, 'api')
        ->putJson("/api/boards/{$board->id}/views/order", ['view_ids' => [$third->id, $second->id, $primary->id]])
        ->assertOk()
        ->assertJsonPath('personal_order', [$third->id, $second->id, $primary->id]);

    $index_response = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/views");
    $index_response->assertJsonPath('personal_order', [$third->id, $second->id, $primary->id]);

    $this->actingAs($user, 'api')
        ->putJson("/api/boards/{$board->id}/views/order", ['view_ids' => [$second->id, $third->id, $primary->id]])
        ->assertOk()
        ->assertJsonPath('personal_order', [$second->id, $third->id, $primary->id]);

    expect(\App\Models\BoardViewUserOrder::where('user_id', $user->id)->where('board_id', $board->id)->count())->toBe(1);
});

test('a personal view order silently drops ids that do not belong to the board', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();
    $other_board = createViewTestBoard();
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $foreign_view = BoardView::factory()->create(['board_id' => $other_board->id, 'is_primary' => true]);

    $this->actingAs($user, 'api')
        ->putJson("/api/boards/{$board->id}/views/order", ['view_ids' => [$foreign_view->id, $view->id]])
        ->assertOk()
        ->assertJsonPath('personal_order', [$view->id]);
});
