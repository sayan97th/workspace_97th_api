<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemDependencyLink;
use App\Models\BoardItemValue;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * A podcast board like the client's "The Campaign": a Date column and a Dependency column scheduling it.
 *
 * @return array{board: WorkspaceNavigationItem, view: BoardView, group: BoardGroup, date: BoardColumn, dependency: BoardColumn, user: User}
 */
function createDependencyBoard(string $date_type = BoardColumn::TYPE_DATE, string $mode = BoardColumn::DEPENDENCY_MODE_STRICT): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);
    $date = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => $date_type, 'label' => 'Date']);
    $dependency = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'type' => BoardColumn::TYPE_DEPENDENCY,
        'label' => 'Depends on',
        'config' => ['date_column_id' => $date->id, 'dependency_mode' => $mode, 'use_working_days' => false],
    ]);

    return ['board' => $board, 'view' => $view, 'group' => $group, 'date' => $date, 'dependency' => $dependency, 'user' => User::factory()->create()];
}

function dependencyItem(array $setup, string $name, mixed $date = null): BoardItem
{
    $item = $setup['board']->items()->create(['group_id' => $setup['group']->id, 'name' => $name, 'position' => 0]);
    if ($date !== null) {
        $item->values()->create(['column_id' => $setup['date']->id, 'value' => $date]);
    }

    return $item;
}

function dateOf(BoardItem $item, BoardColumn $column): mixed
{
    return BoardItemValue::where('item_id', $item->id)->where('column_id', $column->id)->first()?->value;
}

test('setting a lag schedules the dependent item that many days after its predecessor', function () {
    $setup = createDependencyBoard();
    $record = dependencyItem($setup, 'Record Episode', '2026-09-30');
    $show_notes = dependencyItem($setup, 'Write show notes');

    $this->actingAs($setup['user'], 'api')
        ->putJson("/api/boards/{$setup['board']->id}/items/{$show_notes->id}/dependencies/{$setup['dependency']->id}", [
            'links' => [['predecessor_id' => $record->id, 'lag_days' => 7]],
        ])
        ->assertOk()
        ->assertJsonPath("item.dependency_links.{$setup['dependency']->id}.{$record->id}.lag_days", 7)
        ->assertJsonPath("item.values.{$setup['date']->id}", '2026-10-07');

    expect(dateOf($show_notes, $setup['date']))->toBe('2026-10-07');
});

test('changing the predecessor date moves every dependent item and returns them', function () {
    $setup = createDependencyBoard();
    $publish = dependencyItem($setup, 'Publish content', '2026-10-01');
    $email = dependencyItem($setup, 'Send email signature');
    $social = dependencyItem($setup, 'Post on social media');
    $promo = dependencyItem($setup, 'Promo teaser');

    foreach ([[$email, 0], [$social, 14], [$promo, -7]] as [$item, $lag]) {
        $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$item->id}/dependencies/{$setup['dependency']->id}", [
            'links' => [['predecessor_id' => $publish->id, 'lag_days' => $lag]],
        ])->assertOk();
    }

    $response = $this->actingAs($setup['user'], 'api')->patchJson("/api/boards/{$setup['board']->id}/items/{$publish->id}/values", [
        'values' => [(string) $setup['date']->id => '2026-11-10'],
    ])->assertOk();

    expect(dateOf($email, $setup['date']))->toBe('2026-11-10')
        ->and(dateOf($social, $setup['date']))->toBe('2026-11-24')
        ->and(dateOf($promo, $setup['date']))->toBe('2026-11-03')
        ->and(collect($response->json('moved_items'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$email->id, $social->id, $promo->id])->sort()->values()->all());
});

test('a change cascades through a chain of dependencies', function () {
    $setup = createDependencyBoard();
    $record = dependencyItem($setup, 'Record Episode', '2026-09-30');
    $edit = dependencyItem($setup, 'Edit audio');
    $publish = dependencyItem($setup, 'Publish');

    $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$edit->id}/dependencies/{$setup['dependency']->id}", ['links' => [['predecessor_id' => $record->id, 'lag_days' => 3]]])->assertOk();
    $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$publish->id}/dependencies/{$setup['dependency']->id}", ['links' => [['predecessor_id' => $edit->id, 'lag_days' => 4]]])->assertOk();

    $this->actingAs($setup['user'], 'api')->patchJson("/api/boards/{$setup['board']->id}/items/{$record->id}/values", ['values' => [(string) $setup['date']->id => '2026-10-10']])->assertOk();

    expect(dateOf($edit, $setup['date']))->toBe('2026-10-13')
        ->and(dateOf($publish, $setup['date']))->toBe('2026-10-17');
});

test('adding a link without a lag keeps the distance the items already have', function () {
    $setup = createDependencyBoard();
    $record = dependencyItem($setup, 'Record Episode', '2026-09-30');
    $article = dependencyItem($setup, 'Write article', '2026-10-05');

    $this->actingAs($setup['user'], 'api')->patchJson("/api/boards/{$setup['board']->id}/items/{$article->id}/values", [
        'values' => [(string) $setup['dependency']->id => [(string) $record->id]],
    ])->assertOk();

    expect(dateOf($article, $setup['date']))->toBe('2026-10-05')
        ->and(BoardItemDependencyLink::where('item_id', $article->id)->value('lag_days'))->toBe(5);
});

test('moving a dependent item by hand in strict mode updates its lag instead of snapping back', function () {
    $setup = createDependencyBoard();
    $record = dependencyItem($setup, 'Record Episode', '2026-09-30');
    $show_notes = dependencyItem($setup, 'Write show notes');

    $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$show_notes->id}/dependencies/{$setup['dependency']->id}", ['links' => [['predecessor_id' => $record->id, 'lag_days' => 7]]])->assertOk();
    $this->actingAs($setup['user'], 'api')->patchJson("/api/boards/{$setup['board']->id}/items/{$show_notes->id}/values", ['values' => [(string) $setup['date']->id => '2026-10-09']])->assertOk();

    expect(BoardItemDependencyLink::where('item_id', $show_notes->id)->value('lag_days'))->toBe(9);

    $this->actingAs($setup['user'], 'api')->patchJson("/api/boards/{$setup['board']->id}/items/{$record->id}/values", ['values' => [(string) $setup['date']->id => '2026-10-01']])->assertOk();

    expect(dateOf($show_notes, $setup['date']))->toBe('2026-10-10');
});

test('flexible mode only pushes dependent items later', function () {
    $setup = createDependencyBoard(mode: BoardColumn::DEPENDENCY_MODE_FLEXIBLE);
    $record = dependencyItem($setup, 'Record Episode', '2026-09-30');
    $show_notes = dependencyItem($setup, 'Write show notes', '2026-10-20');

    $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$show_notes->id}/dependencies/{$setup['dependency']->id}", ['links' => [['predecessor_id' => $record->id, 'lag_days' => 7]]])->assertOk();
    expect(dateOf($show_notes, $setup['date']))->toBe('2026-10-20');

    $this->actingAs($setup['user'], 'api')->patchJson("/api/boards/{$setup['board']->id}/items/{$record->id}/values", ['values' => [(string) $setup['date']->id => '2026-10-15']])->assertOk();
    expect(dateOf($show_notes, $setup['date']))->toBe('2026-10-22');

    $this->actingAs($setup['user'], 'api')->patchJson("/api/boards/{$setup['board']->id}/items/{$record->id}/values", ['values' => [(string) $setup['date']->id => '2026-10-01']])->assertOk();
    expect(dateOf($show_notes, $setup['date']))->toBe('2026-10-22');
});

test('no action mode never moves a date', function () {
    $setup = createDependencyBoard(mode: BoardColumn::DEPENDENCY_MODE_NONE);
    $record = dependencyItem($setup, 'Record Episode', '2026-09-30');
    $show_notes = dependencyItem($setup, 'Write show notes', '2026-10-02');

    $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$show_notes->id}/dependencies/{$setup['dependency']->id}", ['links' => [['predecessor_id' => $record->id, 'lag_days' => 7]]])->assertOk();
    $this->actingAs($setup['user'], 'api')->patchJson("/api/boards/{$setup['board']->id}/items/{$record->id}/values", ['values' => [(string) $setup['date']->id => '2026-10-20']])->assertOk();

    expect(dateOf($show_notes, $setup['date']))->toBe('2026-10-02');
});

test('timeline links keep the item length and follow the link type', function () {
    $setup = createDependencyBoard(BoardColumn::TYPE_TIMELINE);
    $record = dependencyItem($setup, 'Record Episode', ['start' => '2026-09-28', 'end' => '2026-09-30']);
    $edit = dependencyItem($setup, 'Edit audio', ['start' => '2026-10-10', 'end' => '2026-10-12']);
    $teaser = dependencyItem($setup, 'Teaser', ['start' => '2026-10-10', 'end' => '2026-10-11']);

    $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$edit->id}/dependencies/{$setup['dependency']->id}", ['links' => [['predecessor_id' => $record->id, 'type' => 'fs', 'lag_days' => 0]]])->assertOk();
    $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$teaser->id}/dependencies/{$setup['dependency']->id}", ['links' => [['predecessor_id' => $record->id, 'type' => 'ss', 'lag_days' => 1]]])->assertOk();

    expect(dateOf($edit, $setup['date']))->toBe(['start' => '2026-10-01', 'end' => '2026-10-03'])
        ->and(dateOf($teaser, $setup['date']))->toBe(['start' => '2026-09-29', 'end' => '2026-09-30']);
});

test('working days skip the weekend', function () {
    $setup = createDependencyBoard();
    $setup['dependency']->update(['config' => [...$setup['dependency']->config, 'use_working_days' => true]]);
    $record = dependencyItem($setup, 'Record Episode', '2026-10-02');
    $show_notes = dependencyItem($setup, 'Write show notes');

    $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$show_notes->id}/dependencies/{$setup['dependency']->id}", ['links' => [['predecessor_id' => $record->id, 'lag_days' => 1]]])->assertOk();

    // Friday plus one working day is Monday.
    expect(dateOf($show_notes, $setup['date']))->toBe('2026-10-05');
});

test('a link that would make a loop is refused', function () {
    $setup = createDependencyBoard();
    $record = dependencyItem($setup, 'Record Episode', '2026-09-30');
    $edit = dependencyItem($setup, 'Edit audio');

    $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$edit->id}/dependencies/{$setup['dependency']->id}", ['links' => [['predecessor_id' => $record->id, 'lag_days' => 2]]])->assertOk();

    $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$record->id}/dependencies/{$setup['dependency']->id}", ['links' => [['predecessor_id' => $edit->id]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('links');

    $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$record->id}/dependencies/{$setup['dependency']->id}", ['links' => [['predecessor_id' => $record->id]]])
        ->assertUnprocessable();
});

test('removing a link drops its settings', function () {
    $setup = createDependencyBoard();
    $record = dependencyItem($setup, 'Record Episode', '2026-09-30');
    $edit = dependencyItem($setup, 'Edit audio');

    $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$edit->id}/dependencies/{$setup['dependency']->id}", ['links' => [['predecessor_id' => $record->id, 'lag_days' => 2]]])->assertOk();
    $this->actingAs($setup['user'], 'api')->putJson("/api/boards/{$setup['board']->id}/items/{$edit->id}/dependencies/{$setup['dependency']->id}", ['links' => []])->assertOk();

    expect(BoardItemDependencyLink::where('item_id', $edit->id)->exists())->toBeFalse()
        ->and(BoardItemValue::where('item_id', $edit->id)->where('column_id', $setup['dependency']->id)->value('value'))->toBeNull();
});

test('a new dependency column schedules the first date column in strict mode', function () {
    $setup = createDependencyBoard();

    $response = $this->actingAs($setup['user'], 'api')->postJson("/api/boards/{$setup['board']->id}/columns", [
        'view_id' => $setup['view']->id,
        'key' => 'dependency_2',
        'label' => 'Dependency',
        'type' => BoardColumn::TYPE_DEPENDENCY,
    ])->assertCreated();

    expect($response->json('column.config.date_column_id'))->toBe($setup['date']->id)
        ->and($response->json('column.config.dependency_mode'))->toBe(BoardColumn::DEPENDENCY_MODE_STRICT);
});
