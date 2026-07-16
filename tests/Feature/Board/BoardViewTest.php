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
