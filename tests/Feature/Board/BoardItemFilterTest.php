<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardGroup, 2: User}
 */
function createFilterTestBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return [$board, $group, User::factory()->create()];
}

/**
 * @param  array<int|string, mixed>  $values  column id => value
 */
function createFilterTestItem(WorkspaceNavigationItem $board, BoardGroup $group, string $name, array $values = []): BoardItem
{
    $item = $board->items()->create(['group_id' => $group->id, 'name' => $name, 'position' => $board->items()->count()]);
    foreach ($values as $column_id => $value) {
        $item->values()->create(['column_id' => $column_id, 'value' => $value]);
    }

    return $item;
}

/**
 * @param  array<string, mixed>  $filter_state
 * @return array<int, int>
 */
function filteredItemIds($test, WorkspaceNavigationItem $board, User $user, array $filter_state, string $today = '2026-09-24'): array
{
    $query = http_build_query(['filter_state' => json_encode($filter_state), 'today' => $today]);

    return collect($test->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items?{$query}")->assertOk()->json('data'))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
}

test('an option rule keeps only items holding one of the picked options', function () {
    [$board, $group, $user] = createFilterTestBoard();
    $status = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_STATUS]);
    $done = createFilterTestItem($board, $group, 'Done task', [$status->id => 'done']);
    $stuck = createFilterTestItem($board, $group, 'Stuck task', [$status->id => 'stuck']);
    $empty = createFilterTestItem($board, $group, 'Empty task');

    $is_rule = ['column_id' => (string) $status->id, 'condition' => 'is', 'value' => '', 'values' => ['done']];
    expect(filteredItemIds($this, $board, $user, ['advanced_filter_rows' => [$is_rule]]))->toBe([$done->id]);

    $is_not_rule = [...$is_rule, 'condition' => 'is_not'];
    expect(filteredItemIds($this, $board, $user, ['advanced_filter_rows' => [$is_not_rule]]))->toEqualCanonicalizing([$stuck->id, $empty->id]);

    $empty_rule = ['column_id' => (string) $status->id, 'condition' => 'is_empty', 'value' => ''];
    expect(filteredItemIds($this, $board, $user, ['advanced_filter_rows' => [$empty_rule]]))->toBe([$empty->id]);
});

test('number rules compare numerically, including between', function () {
    [$board, $group, $user] = createFilterTestBoard();
    $budget = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_NUMBER]);
    $small = createFilterTestItem($board, $group, 'Small', [$budget->id => 5]);
    $large = createFilterTestItem($board, $group, 'Large', [$budget->id => 120]);

    $greater = ['column_id' => (string) $budget->id, 'condition' => 'greater_than', 'value' => '10'];
    expect(filteredItemIds($this, $board, $user, ['advanced_filter_rows' => [$greater]]))->toBe([$large->id]);

    $between = ['column_id' => (string) $budget->id, 'condition' => 'between', 'value' => '', 'values' => ['1', '9']];
    expect(filteredItemIds($this, $board, $user, ['advanced_filter_rows' => [$between]]))->toBe([$small->id]);
});

test('relative date rules resolve against the viewer\'s today, with Monday week starts', function () {
    [$board, $group, $user] = createFilterTestBoard();
    $due = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_DATE]);
    $timeline = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_TIMELINE]);
    // 2026-09-24 is a Thursday, so "this week" is Monday 21 to Sunday 27.
    $this_week = createFilterTestItem($board, $group, 'This week', [$due->id => '2026-09-27T10:30']);
    $last_week = createFilterTestItem($board, $group, 'Last week', [$due->id => '2026-09-20']);
    $spanning = createFilterTestItem($board, $group, 'Spanning', [$timeline->id => ['start' => '2026-09-01', 'end' => '2026-09-22']]);

    $due_this_week = ['column_id' => (string) $due->id, 'condition' => 'is', 'value' => 'this_week'];
    expect(filteredItemIds($this, $board, $user, ['advanced_filter_rows' => [$due_this_week]]))->toBe([$this_week->id]);

    $due_past = ['column_id' => (string) $due->id, 'condition' => 'before', 'value' => 'today'];
    expect(filteredItemIds($this, $board, $user, ['advanced_filter_rows' => [$due_past]]))->toBe([$last_week->id]);

    // A timeline overlapping the week matches, even though it started weeks earlier.
    $timeline_this_week = ['column_id' => (string) $timeline->id, 'condition' => 'is', 'value' => 'this_week'];
    expect(filteredItemIds($this, $board, $user, ['advanced_filter_rows' => [$timeline_this_week]]))->toBe([$spanning->id]);
});

test('advanced rules combine with the top level operator and nested groups', function () {
    [$board, $group, $user] = createFilterTestBoard();
    $status = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_STATUS]);
    $done_flag = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_CHECKBOX]);
    $a = createFilterTestItem($board, $group, 'Alpha', [$status->id => 'done', $done_flag->id => true]);
    $b = createFilterTestItem($board, $group, 'Beta', [$status->id => 'stuck']);
    createFilterTestItem($board, $group, 'Gamma', [$status->id => 'working']);

    $is_done = ['column_id' => (string) $status->id, 'condition' => 'is', 'value' => '', 'values' => ['done']];
    $is_stuck = ['column_id' => (string) $status->id, 'condition' => 'is', 'value' => '', 'values' => ['stuck']];
    $checked = ['column_id' => (string) $done_flag->id, 'condition' => 'is_checked', 'value' => ''];

    expect(filteredItemIds($this, $board, $user, [
        'advanced_filter_rows' => [$is_done, $is_stuck],
        'advanced_filter_operator' => 'or',
    ]))->toEqualCanonicalizing([$a->id, $b->id]);

    // Name starts with "a" AND (stuck OR checked): only Alpha.
    expect(filteredItemIds($this, $board, $user, [
        'advanced_filter_rows' => [['column_id' => 'name', 'condition' => 'starts_with', 'value' => 'a']],
        'advanced_filter_groups' => [['join_operator' => 'or', 'rules' => [$is_stuck, $checked]]],
    ]))->toBe([$a->id]);
});

test('people rules resolve Me to the signed in user and the person filter checks every people column', function () {
    [$board, $group, $user] = createFilterTestBoard();
    $other = User::factory()->create();
    $owner = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_PEOPLE]);
    $mine = createFilterTestItem($board, $group, 'Mine', [$owner->id => [$user->id]]);
    $theirs = createFilterTestItem($board, $group, 'Theirs', [$owner->id => [$other->id]]);

    $me_rule = ['column_id' => (string) $owner->id, 'condition' => 'is', 'value' => '', 'values' => ['__me__']];
    expect(filteredItemIds($this, $board, $user, ['advanced_filter_rows' => [$me_rule]]))->toBe([$mine->id]);

    expect(filteredItemIds($this, $board, $user, ['selected_person_ids' => [(string) $other->id]]))->toBe([$theirs->id]);
});

test('quick filter picks match option ids, blanks, date buckets and the group field', function () {
    [$board, $group, $user] = createFilterTestBoard();
    $second_group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $status = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_STATUS]);
    $due = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_DATE]);
    $overdue = createFilterTestItem($board, $group, 'Overdue', [$status->id => 'done', $due->id => '2026-09-01']);
    $blank = createFilterTestItem($board, $group, 'Blank');
    $elsewhere = createFilterTestItem($board, $second_group, 'Elsewhere', [$status->id => 'done']);

    expect(filteredItemIds($this, $board, $user, ['quick_filter_selections' => [(string) $status->id => ['__blank__']]]))->toBe([$blank->id]);
    expect(filteredItemIds($this, $board, $user, ['quick_filter_selections' => [(string) $due->id => ['overdue']]]))->toBe([$overdue->id]);
    expect(filteredItemIds($this, $board, $user, [
        'quick_filter_selections' => ['__group__' => [(string) $second_group->id], (string) $status->id => ['done']],
    ]))->toBe([$elsewhere->id]);
});

test('incomplete rules and malformed filter state do not narrow the items', function () {
    [$board, $group, $user] = createFilterTestBoard();
    $status = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_STATUS]);
    createFilterTestItem($board, $group, 'One', [$status->id => 'done']);
    createFilterTestItem($board, $group, 'Two');

    $incomplete = ['column_id' => (string) $status->id, 'condition' => 'is', 'value' => '', 'values' => []];
    expect(filteredItemIds($this, $board, $user, ['advanced_filter_rows' => [$incomplete]]))->toHaveCount(2);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/items?filter_state=not-json")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('save as new view copies the tab with the given state remapped onto the copy', function () {
    [$board, $group, $user] = createFilterTestBoard();
    $status = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_STATUS]);
    $due = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_DATE]);
    $view = BoardView::where('board_id', $board->id)->where('is_primary', true)->firstOrFail();
    $view->update(['is_locked' => true]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/views/{$view->id}/duplicate", [
        'label' => 'Main table (filtered)',
        'filter_state' => [
            'search_query' => '',
            'search_column_ids' => [],
            'selected_person_ids' => [],
            'quick_filter_selections' => ['__group__' => [(string) $group->id]],
            'advanced_filter_rows' => [['column_id' => (string) $status->id, 'condition' => 'is', 'value' => '', 'values' => ['done']]],
            'advanced_filter_groups' => [['join_operator' => 'or', 'rules' => [['column_id' => (string) $due->id, 'condition' => 'is', 'value' => 'this_week', 'values' => []]]]],
            'advanced_filter_operator' => 'and',
            'quick_filter_column_ids' => [(string) $status->id],
        ],
        'group_by_option_id' => "{$due->id}:month",
    ]);

    $response->assertCreated()->assertJsonPath('view.label', 'Main table (filtered)');

    $copy = BoardView::findOrFail($response->json('view.id'));
    $copied_status = $copy->columns()->where('type', BoardColumn::TYPE_STATUS)->firstOrFail();
    $copied_due = $copy->columns()->where('type', BoardColumn::TYPE_DATE)->firstOrFail();
    $copied_group = $copy->groups()->firstOrFail();

    expect($copy->filter_state['advanced_filter_rows'][0]['column_id'])->toBe((string) $copied_status->id)
        ->and($copy->filter_state['advanced_filter_groups'][0]['rules'][0]['column_id'])->toBe((string) $copied_due->id)
        ->and($copy->filter_state['quick_filter_selections']['__group__'])->toBe([(string) $copied_group->id])
        ->and($copy->filter_state['quick_filter_column_ids'])->toBe([(string) $copied_status->id])
        ->and($copy->group_by_option_id)->toBe("{$copied_due->id}:month");

    // The locked source keeps its own saved state.
    expect($view->fresh()->filter_state)->toBeNull();
});

test('a plain duplicate of a locked view is still rejected', function () {
    [$board, , $user] = createFilterTestBoard();
    $view = BoardView::where('board_id', $board->id)->where('is_primary', true)->firstOrFail();
    $view->update(['is_locked' => true]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$view->id}/duplicate")
        ->assertStatus(423);
});
