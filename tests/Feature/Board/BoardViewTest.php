<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\BoardView;
use App\Models\BoardViewUserOrder;
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

test('a view defaults to the table view type when none is given', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views", ['label' => 'By owner'])
        ->assertCreated()
        ->assertJsonPath('view.view_type', 'table');
});

test('a view can be created with a specific view type', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views", ['label' => 'Sprint board', 'view_type' => 'kanban'])
        ->assertCreated()
        ->assertJsonPath('view.view_type', 'kanban');

    $this->assertDatabaseHas('board_views', ['board_id' => $board->id, 'label' => 'Sprint board', 'view_type' => 'kanban']);
});

test('creating a view with an unrecognized view type is rejected', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views", ['label' => 'Bad view', 'view_type' => 'not_a_real_type'])
        ->assertInvalid(['view_type']);
});

test('duplicating a view carries over its view type', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();
    $view = BoardView::factory()->kanban()->create(['board_id' => $board->id]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$view->id}/duplicate")
        ->assertCreated()
        ->assertJsonPath('view.view_type', 'kanban');
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

test('saving doc content on a doc view persists and round-trips the markdown', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false, 'view_type' => 'doc']);

    $markdown = "# Heading\n\nSome **bold** text and a list:\n\n- one\n- two";

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/views/{$view->id}", ['doc_content' => $markdown])
        ->assertOk()
        ->assertJsonPath('view.doc_content', $markdown);

    $reload_response = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/views");
    $saved_view = collect($reload_response->json('data'))->firstWhere('id', $view->id);

    expect($saved_view['doc_content'])->toBe($markdown);
});

test('duplicating a doc view carries over its doc content', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();
    $view = BoardView::factory()->create([
        'board_id' => $board->id,
        'is_primary' => false,
        'view_type' => 'doc',
        'doc_content' => '# Original notes',
    ]);

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$view->id}/duplicate")
        ->assertCreated()
        ->assertJsonPath('view.doc_content', '# Original notes');

    expect($response->json('view.id'))->not->toBe($view->id);
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

test('duplicating a view deep-clones its tables, columns, items and cell values', function () {
    $user = User::factory()->create();
    $board = createViewTestBoard();
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true, 'label' => 'Main table']);

    $status_column = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'key' => 'status',
        'type' => BoardColumn::TYPE_STATUS,
        'position' => 0,
        'config' => ['options' => [['id' => 'opt_1', 'label' => 'Done', 'color' => '#00c875']]],
    ]);
    $text_column = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'key' => 'notes',
        'type' => BoardColumn::TYPE_TEXT,
        'position' => 1,
    ]);

    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'position' => 0]);
    $item = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id, 'name' => 'Task one']);
    BoardItemValue::factory()->create(['item_id' => $item->id, 'column_id' => $status_column->id, 'value' => 'opt_1']);
    BoardItemValue::factory()->create(['item_id' => $item->id, 'column_id' => $text_column->id, 'value' => 'hello world']);

    // Another, unrelated tab on the same board — must be left untouched.
    $other_view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);
    BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $other_view->id]);

    $view->update([
        'filter_state' => [
            'search_query' => 'task',
            'search_column_ids' => [(string) $text_column->id],
            'selected_person_ids' => [],
            'quick_filter_selections' => [(string) $status_column->id => ['opt_1']],
            'advanced_filter_rows' => [['id' => 'row_1', 'column_id' => (string) $status_column->id, 'condition' => 'is', 'value' => 'opt_1']],
        ],
        'sort_state' => [['id' => 'sort_1', 'sort_option_id' => (string) $text_column->id, 'direction' => 'asc', 'join_operator' => 'and']],
        'hidden_column_ids' => [(string) $text_column->id],
        'pinned_column_ids' => [(string) $status_column->id],
        'group_by_option_id' => (string) $status_column->id,
        'conditional_color_rules' => [['id' => 'color_1', 'color' => '#e2445c', 'scope' => 'cell', 'column_id' => (string) $status_column->id, 'condition' => 'is', 'value' => 'opt_1']],
    ]);

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$view->id}/duplicate")
        ->assertCreated()
        ->assertJsonPath('view.label', 'Main table (copy)');

    $copy_id = $response->json('view.id');

    // Structure: 2 columns, 1 group, 1 item, 2 values — all newly minted rows, not reused ids.
    $copy_columns = BoardColumn::where('board_view_id', $copy_id)->orderBy('position')->get();
    expect($copy_columns)->toHaveCount(2);
    expect($copy_columns->pluck('id')->all())->not->toContain($status_column->id, $text_column->id);
    expect($copy_columns->pluck('key')->all())->toBe(['status', 'notes']);

    $copy_groups = BoardGroup::where('board_view_id', $copy_id)->get();
    expect($copy_groups)->toHaveCount(1);
    expect($copy_groups->first()->id)->not->toBe($group->id);

    $copy_items = BoardItem::where('group_id', $copy_groups->first()->id)->get();
    expect($copy_items)->toHaveCount(1);
    expect($copy_items->first()->id)->not->toBe($item->id);
    expect($copy_items->first()->name)->toBe('Task one');

    $copy_values = BoardItemValue::where('item_id', $copy_items->first()->id)->get()->keyBy('column_id');
    $copy_status_column = $copy_columns->firstWhere('key', 'status');
    $copy_text_column = $copy_columns->firstWhere('key', 'notes');
    expect($copy_values[$copy_status_column->id]->value)->toBe('opt_1');
    expect($copy_values[$copy_text_column->id]->value)->toBe('hello world');

    // Saved filter/sort/display state now points at the copy's own column ids.
    $copy_view = BoardView::find($copy_id);
    expect($copy_view->filter_state['search_column_ids'])->toBe([(string) $copy_text_column->id]);
    expect($copy_view->filter_state['quick_filter_selections'])->toBe([(string) $copy_status_column->id => ['opt_1']]);
    expect($copy_view->filter_state['advanced_filter_rows'][0]['column_id'])->toBe((string) $copy_status_column->id);
    expect($copy_view->sort_state[0]['sort_option_id'])->toBe((string) $copy_text_column->id);
    expect($copy_view->hidden_column_ids)->toBe([(string) $copy_text_column->id]);
    expect($copy_view->pinned_column_ids)->toBe([(string) $copy_status_column->id]);
    expect($copy_view->group_by_option_id)->toBe((string) $copy_status_column->id);
    expect($copy_view->conditional_color_rules[0]['column_id'])->toBe((string) $copy_status_column->id);

    // The unrelated tab's own column is untouched.
    expect(BoardColumn::where('board_view_id', $other_view->id)->count())->toBe(1);
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

    expect(BoardViewUserOrder::where('user_id', $user->id)->where('board_id', $board->id)->count())->toBe(1);
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
