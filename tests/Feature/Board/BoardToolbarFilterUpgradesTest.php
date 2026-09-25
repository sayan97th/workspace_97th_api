<?php

use App\Models\AccountTeam;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardSavedFilter;
use App\Models\BoardViewUserState;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardGroup, 2: User}
 */
function createToolbarUpgradeBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $user = User::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'member']);

    return [$board, $group, $user];
}

/**
 * @param  array<int|string, mixed>  $values  column id => value
 */
function createToolbarUpgradeItem(WorkspaceNavigationItem $board, BoardGroup $group, string $name, array $values = [], ?BoardItem $parent = null): BoardItem
{
    $item = $board->items()->create([
        'group_id' => $group->id,
        'parent_id' => $parent?->id,
        'name' => $name,
        'position' => $board->items()->count(),
    ]);
    foreach ($values as $column_id => $value) {
        $item->values()->create(['column_id' => $column_id, 'value' => $value]);
    }

    return $item;
}

/**
 * @param  array<string, mixed>  $filter_state
 * @param  array<string, string>  $extra_query
 * @return array<int, int>
 */
function toolbarUpgradeItemIds($test, WorkspaceNavigationItem $board, User $user, array $filter_state, array $extra_query = []): array
{
    $query = http_build_query(['filter_state' => json_encode($filter_state), 'today' => '2026-09-24', ...$extra_query]);

    return collect($test->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items?{$query}")->assertOk()->json('data'))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
}

test('quick filter exclusions hide items holding the excluded value', function () {
    [$board, $group, $user] = createToolbarUpgradeBoard();
    $status = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_STATUS]);
    createToolbarUpgradeItem($board, $group, 'Done', [$status->id => 'done']);
    $stuck = createToolbarUpgradeItem($board, $group, 'Stuck', [$status->id => 'stuck']);
    $blank = createToolbarUpgradeItem($board, $group, 'Blank');

    $ids = toolbarUpgradeItemIds($this, $board, $user, ['quick_filter_exclusions' => [(string) $status->id => ['done']]]);

    expect($ids)->toEqualCanonicalizing([$stuck->id, $blank->id]);
});

test('a paused advanced rule narrows nothing', function () {
    [$board, $group, $user] = createToolbarUpgradeBoard();
    $status = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_STATUS]);
    $done = createToolbarUpgradeItem($board, $group, 'Done', [$status->id => 'done']);
    $stuck = createToolbarUpgradeItem($board, $group, 'Stuck', [$status->id => 'stuck']);

    $rule = ['column_id' => (string) $status->id, 'condition' => 'is', 'value' => '', 'values' => ['done'], 'is_disabled' => true];

    expect(toolbarUpgradeItemIds($this, $board, $user, ['advanced_filter_rows' => [$rule]]))->toEqualCanonicalizing([$done->id, $stuck->id]);
});

test('subitem rules match a parent through one of its subitems only while filter subitems is on', function () {
    [$board, $group, $user] = createToolbarUpgradeBoard();
    $item_status = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id, 'type' => BoardColumn::TYPE_STATUS]);
    $sub_status = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $group->board_view_id,
        'scope' => BoardColumn::SCOPE_SUBITEM,
        'type' => BoardColumn::TYPE_STATUS,
    ]);
    $alpha = createToolbarUpgradeItem($board, $group, 'Alpha', [$item_status->id => 'done']);
    createToolbarUpgradeItem($board, $group, 'Alpha one', [$sub_status->id => 'stuck'], $alpha);
    $beta = createToolbarUpgradeItem($board, $group, 'Beta', [$item_status->id => 'stuck']);
    createToolbarUpgradeItem($board, $group, 'Beta one', [$sub_status->id => 'done'], $beta);
    $gamma = createToolbarUpgradeItem($board, $group, 'Gamma');

    $sub_done = ['column_id' => (string) $sub_status->id, 'condition' => 'is', 'value' => '', 'values' => ['done']];

    expect(toolbarUpgradeItemIds($this, $board, $user, ['advanced_filter_rows' => [$sub_done], 'include_subitems' => true]))->toBe([$beta->id]);

    // Off, the subitem column is unknown to the filter, so the rule passes every item.
    expect(toolbarUpgradeItemIds($this, $board, $user, ['advanced_filter_rows' => [$sub_done]]))
        ->toEqualCanonicalizing([$alpha->id, $beta->id, $gamma->id]);

    // Item and subitem rules must hold on the same pair.
    $item_done = ['column_id' => (string) $item_status->id, 'condition' => 'is', 'value' => '', 'values' => ['done']];
    expect(toolbarUpgradeItemIds($this, $board, $user, ['advanced_filter_rows' => [$sub_done, $item_done], 'include_subitems' => true]))->toBe([]);

    // A parent without subitems reads the subitem column as empty.
    $sub_empty = ['column_id' => (string) $sub_status->id, 'condition' => 'is_empty', 'value' => ''];
    expect(toolbarUpgradeItemIds($this, $board, $user, ['advanced_filter_rows' => [$sub_empty], 'include_subitems' => true]))->toBe([$gamma->id]);
});

test('the person filter reads picked teams and only the chosen people columns', function () {
    [$board, $group, $user] = createToolbarUpgradeBoard();
    $owner = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_PEOPLE]);
    $reviewer = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_PEOPLE]);
    $designer = User::factory()->create();
    $team = AccountTeam::factory()->create(['name' => 'Design']);
    $team->members()->attach($designer->id);

    $owned = createToolbarUpgradeItem($board, $group, 'Owned', [$owner->id => [$designer->id]]);
    $reviewed = createToolbarUpgradeItem($board, $group, 'Reviewed', [$reviewer->id => [$designer->id]]);
    createToolbarUpgradeItem($board, $group, 'Nobody');

    expect(toolbarUpgradeItemIds($this, $board, $user, ['selected_team_ids' => [(string) $team->id]]))
        ->toEqualCanonicalizing([$owned->id, $reviewed->id]);

    expect(toolbarUpgradeItemIds($this, $board, $user, [
        'selected_person_ids' => [(string) $designer->id],
        'person_column_ids' => [(string) $owner->id],
    ]))->toBe([$owned->id]);
});

test('items expose their author and timestamps, and creation date filters use the viewer time zone', function () {
    [$board, $group, $user] = createToolbarUpgradeBoard();
    $item = createToolbarUpgradeItem($board, $group, 'Late night');
    // 03:00 UTC on the 25th is still the 24th in Mexico City (UTC-6).
    $item->forceFill(['created_by_id' => $user->id, 'created_at' => '2026-09-25 03:00:00', 'updated_at' => '2026-09-25 03:00:00'])->saveQuietly();

    $row = collect($this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items")->assertOk()->json('data'))->firstWhere('id', $item->id);
    expect($row['created_by_id'])->toBe($user->id)
        ->and($row['created_at'])->not->toBeNull()
        ->and($row['last_updated_at'])->not->toBeNull();

    $created_today = ['column_id' => '__created_at__', 'condition' => 'is', 'value' => 'today'];
    expect(toolbarUpgradeItemIds($this, $board, $user, ['advanced_filter_rows' => [$created_today]], ['timezone' => 'America/Mexico_City']))->toBe([$item->id]);
    expect(toolbarUpgradeItemIds($this, $board, $user, ['advanced_filter_rows' => [$created_today]], ['timezone' => 'UTC']))->toBe([]);

    $created_by_me = ['column_id' => '__created_by__', 'condition' => 'is', 'value' => '', 'values' => ['__me__']];
    expect(toolbarUpgradeItemIds($this, $board, $user, ['advanced_filter_rows' => [$created_by_me]]))->toBe([$item->id]);
});

test('update matches returns root items whose published updates contain the term', function () {
    [$board, $group, $user] = createToolbarUpgradeBoard();
    $parent = createToolbarUpgradeItem($board, $group, 'Parent');
    $sub_item = createToolbarUpgradeItem($board, $group, 'Child', [], $parent);
    $direct = createToolbarUpgradeItem($board, $group, 'Direct');
    $scheduled = createToolbarUpgradeItem($board, $group, 'Scheduled');
    createToolbarUpgradeItem($board, $group, 'Silent');

    $sub_item->comments()->create(['user_id' => $user->id, 'body' => 'Waiting on the Invoice']);
    $direct->comments()->create(['user_id' => $user->id, 'body' => 'invoice sent']);
    $scheduled->comments()->create(['user_id' => $user->id, 'body' => 'invoice later', 'scheduled_at' => now()->addDay()]);

    $ids = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items/update-matches?q=invoice")->assertOk()->json('data');

    expect($ids)->toEqualCanonicalizing([$parent->id, $direct->id]);
});

test('update matches is closed to people outside the board workspace', function () {
    [$board] = createToolbarUpgradeBoard();

    $this->actingAs(User::factory()->create(), 'api')->getJson("/api/boards/{$board->id}/items/update-matches?q=x")->assertForbidden();
});

test('a viewer remembers their own changes per view and can reset them', function () {
    [$board, $group, $user] = createToolbarUpgradeBoard();
    $view_id = $group->board_view_id;
    $payload = [
        'filter_state' => ['quick_filter_selections' => ['12' => ['done']], 'quick_filter_exclusions' => []],
        'sort_state' => [['sort_option_id' => 'name', 'direction' => 'desc', 'join_operator' => 'and']],
        'hidden_column_ids' => ['12'],
        'group_by_option_id' => null,
    ];

    $this->actingAs($user, 'api')->putJson("/api/boards/{$board->id}/views/{$view_id}/personal-state", $payload)->assertOk();

    $index = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/views")->assertOk();
    expect($index->json("personal_states.{$view_id}.filter_state.quick_filter_selections"))->toBe(['12' => ['done']])
        ->and($index->json("personal_states.{$view_id}.hidden_column_ids"))->toBe(['12']);

    // Someone else in the workspace sees no remembered changes.
    $other = User::factory()->create();
    $board->workspace->users()->attach($other->id, ['role' => 'member']);
    expect($this->actingAs($other, 'api')->getJson("/api/boards/{$board->id}/views")->json('personal_states'))->toBe([]);

    $this->actingAs($user, 'api')->deleteJson("/api/boards/{$board->id}/views/{$view_id}/personal-state")->assertOk();
    expect(BoardViewUserState::query()->count())->toBe(0);
});

test('saved filters are private to their owner', function () {
    [$board, , $user] = createToolbarUpgradeBoard();
    $other = User::factory()->create();
    $board->workspace->users()->attach($other->id, ['role' => 'member']);

    $created = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/saved-filters", [
        'name' => 'My overdue tasks',
        'filter_state' => ['quick_filter_selections' => ['7' => ['overdue']]],
    ])->assertCreated()->json('saved_filter');

    expect($this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/saved-filters")->json('data.0.filter_state.quick_filter_selections'))
        ->toBe(['7' => ['overdue']]);
    expect($this->actingAs($other, 'api')->getJson("/api/boards/{$board->id}/saved-filters")->json('data'))->toBe([]);

    $this->actingAs($other, 'api')->patchJson("/api/boards/{$board->id}/saved-filters/{$created['id']}", ['name' => 'Mine now'])->assertNotFound();
    $this->actingAs($other, 'api')->deleteJson("/api/boards/{$board->id}/saved-filters/{$created['id']}")->assertNotFound();

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/saved-filters/{$created['id']}", ['name' => 'Overdue'])
        ->assertOk()
        ->assertJsonPath('saved_filter.name', 'Overdue');
    $this->actingAs($user, 'api')->deleteJson("/api/boards/{$board->id}/saved-filters/{$created['id']}")->assertOk();
    expect(BoardSavedFilter::query()->count())->toBe(0);
});

test('saved filters are closed to people outside the board workspace', function () {
    [$board] = createToolbarUpgradeBoard();

    $this->actingAs(User::factory()->create(), 'api')->getJson("/api/boards/{$board->id}/saved-filters")->assertForbidden();
});
