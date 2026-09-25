<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardItemValue;
use App\Models\BoardTag;
use App\Models\BoardTemplate;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Carbon;

/**
 * A board with one Table tab: a Status, People, Date, Number and Tags column,
 * one group, two items and a subitem.
 *
 * @return array<string, mixed>
 */
function createRoundSixBoard(User $owner): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        'label' => 'Launch plan',
        'created_by_id' => $owner->id,
    ]);
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true, 'label' => 'Main table']);

    $status = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'key' => 'status',
        'label' => 'Status',
        'type' => BoardColumn::TYPE_STATUS,
        'position' => 0,
        'config' => ['options' => [
            ['id' => 'working', 'label' => 'Working on it', 'color' => '#fdab3d'],
            ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
        ]],
    ]);
    $people = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'key' => 'owner', 'label' => 'Owner', 'type' => BoardColumn::TYPE_PEOPLE, 'position' => 1]);
    $date = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'key' => 'due', 'label' => 'Due', 'type' => BoardColumn::TYPE_DATE, 'position' => 2]);
    $hours = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'key' => 'hours', 'label' => 'Hours', 'type' => BoardColumn::TYPE_NUMBER, 'position' => 3]);
    $tags = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'key' => 'tags', 'label' => 'Tags', 'type' => BoardColumn::TYPE_TAGS, 'position' => 4]);
    $sub_text = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'key' => 'sub_notes',
        'label' => 'Notes',
        'type' => BoardColumn::TYPE_TEXT,
        'scope' => BoardColumn::SCOPE_SUBITEM,
        'position' => 0,
    ]);
    $tag = BoardTag::factory()->create(['board_id' => $board->id, 'label' => 'Urgent']);

    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Sprint', 'is_priority' => true, 'position' => 0]);
    $first = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id, 'name' => 'Write brief', 'position' => 0]);
    $second = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id, 'name' => 'Design banner', 'position' => 1]);
    $subitem = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id, 'parent_id' => $first->id, 'name' => 'Collect quotes', 'position' => 0]);

    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    BoardItemValue::factory()->create(['item_id' => $first->id, 'column_id' => $status->id, 'value' => 'done']);
    BoardItemValue::factory()->create(['item_id' => $first->id, 'column_id' => $people->id, 'value' => [(string) $owner->id]]);
    BoardItemValue::factory()->create(['item_id' => $first->id, 'column_id' => $date->id, 'value' => $monday->toDateString()]);
    BoardItemValue::factory()->create(['item_id' => $first->id, 'column_id' => $hours->id, 'value' => 6]);
    BoardItemValue::factory()->create(['item_id' => $first->id, 'column_id' => $tags->id, 'value' => [(string) $tag->id]]);
    BoardItemValue::factory()->create(['item_id' => $second->id, 'column_id' => $status->id, 'value' => 'working']);
    BoardItemValue::factory()->create(['item_id' => $second->id, 'column_id' => $people->id, 'value' => [(string) $owner->id]]);
    BoardItemValue::factory()->create(['item_id' => $second->id, 'column_id' => $date->id, 'value' => $monday->copy()->addDays(2)->toDateString()]);
    BoardItemValue::factory()->create(['item_id' => $second->id, 'column_id' => $hours->id, 'value' => 4]);
    BoardItemValue::factory()->create(['item_id' => $subitem->id, 'column_id' => $sub_text->id, 'value' => 'three vendors']);

    return compact('workspace', 'board', 'view', 'status', 'people', 'date', 'hours', 'tags', 'sub_text', 'tag', 'group', 'first', 'second', 'subitem');
}

// ---- batch cell values -----------------------------------------------------

test('a range paste writes several items and columns in one request', function () {
    $user = User::factory()->create();
    $data = createRoundSixBoard($user);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$data['board']->id}/items/cell-values", ['cells' => [
            ['item_id' => $data['first']->id, 'values' => [(string) $data['status']->id => 'working', (string) $data['hours']->id => 10]],
            ['item_id' => $data['second']->id, 'values' => [(string) $data['status']->id => 'done', (string) $data['hours']->id => 12]],
        ]])
        ->assertOk()
        ->assertJsonCount(2, 'items');

    expect(BoardItemValue::where('item_id', $data['first']->id)->where('column_id', $data['status']->id)->value('value'))->toBe('working')
        ->and(BoardItemValue::where('item_id', $data['second']->id)->where('column_id', $data['hours']->id)->value('value'))->toBe(12);
});

test('a range paste is refused as a whole when one column is restricted', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $data = createRoundSixBoard($owner);
    $data['hours']->update(['edit_restriction' => ['user_ids' => [$owner->id], 'team_ids' => []]]);

    $this->actingAs($member, 'api')
        ->patchJson("/api/boards/{$data['board']->id}/items/cell-values", ['cells' => [
            ['item_id' => $data['first']->id, 'values' => [(string) $data['status']->id => 'working', (string) $data['hours']->id => 1]],
        ]])
        ->assertForbidden();

    expect(BoardItemValue::where('item_id', $data['first']->id)->where('column_id', $data['status']->id)->value('value'))->toBe('done');
});

test('a range paste rejects items of another board', function () {
    $user = User::factory()->create();
    $data = createRoundSixBoard($user);
    $other = createRoundSixBoard($user);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$data['board']->id}/items/cell-values", ['cells' => [
            ['item_id' => $other['first']->id, 'values' => [(string) $other['status']->id => 'working']],
        ]])
        ->assertUnprocessable();
});

// ---- duplicate board options -----------------------------------------------

test('duplicating a board with structure only copies columns and groups but no items', function () {
    $user = User::factory()->create();
    $data = createRoundSixBoard($user);

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$data['workspace']->slug}/navigation/{$data['board']->id}/duplicate", ['mode' => 'structure', 'label' => 'Launch plan Q2'])
        ->assertCreated()
        ->assertJsonPath('item.label', 'Launch plan Q2');

    $copy_id = $response->json('item.id');
    expect(BoardColumn::where('board_id', $copy_id)->count())->toBe(6)
        ->and(BoardGroup::where('board_id', $copy_id)->count())->toBe(1)
        ->and(BoardItem::where('board_id', $copy_id)->count())->toBe(0);
});

test('duplicating a board with items keeps subitems, column scopes, group priority and tags', function () {
    $user = User::factory()->create();
    $data = createRoundSixBoard($user);

    $copy_id = $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$data['workspace']->slug}/navigation/{$data['board']->id}/duplicate", ['mode' => 'items'])
        ->assertCreated()
        ->assertJsonPath('item.label', 'Launch plan (copy)')
        ->json('item.id');

    $copied_sub = BoardItem::where('board_id', $copy_id)->where('name', 'Collect quotes')->firstOrFail();
    $copied_parent = BoardItem::where('board_id', $copy_id)->where('name', 'Write brief')->firstOrFail();
    $copied_sub_column = BoardColumn::where('board_id', $copy_id)->where('key', 'sub_notes')->firstOrFail();
    $copied_tags_column = BoardColumn::where('board_id', $copy_id)->where('key', 'tags')->firstOrFail();
    $copied_tag = BoardTag::where('board_id', $copy_id)->where('label', 'Urgent')->firstOrFail();

    expect($copied_sub->parent_id)->toBe($copied_parent->id)
        ->and($copied_sub_column->scope)->toBe(BoardColumn::SCOPE_SUBITEM)
        ->and(BoardGroup::where('board_id', $copy_id)->value('is_priority'))->toBeTruthy()
        ->and(BoardItemValue::where('item_id', $copied_parent->id)->where('column_id', $copied_tags_column->id)->value('value'))->toBe([(string) $copied_tag->id]);
});

test('duplicating a board with updates copies each item updates and replies', function () {
    $user = User::factory()->create();
    $data = createRoundSixBoard($user);
    $update = BoardItemComment::create(['item_id' => $data['first']->id, 'user_id' => $user->id, 'body' => 'Kickoff notes']);
    BoardItemComment::create(['item_id' => $data['first']->id, 'user_id' => $user->id, 'parent_id' => $update->id, 'body' => 'Thanks']);

    $copy_id = $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$data['workspace']->slug}/navigation/{$data['board']->id}/duplicate", ['mode' => 'items_updates'])
        ->assertCreated()
        ->json('item.id');

    $copied_item = BoardItem::where('board_id', $copy_id)->where('name', 'Write brief')->firstOrFail();
    $copied_update = BoardItemComment::where('item_id', $copied_item->id)->whereNull('parent_id')->firstOrFail();

    expect($copied_update->body)->toBe('Kickoff notes')
        ->and(BoardItemComment::where('parent_id', $copied_update->id)->value('body'))->toBe('Thanks')
        ->and(BoardItemComment::where('item_id', $data['first']->id)->count())->toBe(2);
});

test('duplicating a board with items does not copy updates', function () {
    $user = User::factory()->create();
    $data = createRoundSixBoard($user);
    BoardItemComment::create(['item_id' => $data['first']->id, 'user_id' => $user->id, 'body' => 'Kickoff notes']);

    $copy_id = $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$data['workspace']->slug}/navigation/{$data['board']->id}/duplicate", ['mode' => 'items'])
        ->json('item.id');

    $copied_item = BoardItem::where('board_id', $copy_id)->where('name', 'Write brief')->firstOrFail();
    expect(BoardItemComment::where('item_id', $copied_item->id)->count())->toBe(0);
});

test('duplicating a board points its Workload tab at the copied columns', function () {
    $user = User::factory()->create();
    $data = createRoundSixBoard($user);
    BoardView::factory()->create([
        'board_id' => $data['board']->id,
        'label' => 'Workload',
        'view_type' => 'workload',
        'is_primary' => false,
        'position' => 1,
        'workload_config' => ['source_view_id' => $data['view']->id, 'people_column_id' => (string) $data['people']->id],
    ]);

    $copy_id = $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$data['workspace']->slug}/navigation/{$data['board']->id}/duplicate")
        ->json('item.id');

    $copied_workload = BoardView::where('board_id', $copy_id)->where('view_type', 'workload')->firstOrFail();
    $copied_primary = BoardView::where('board_id', $copy_id)->where('is_primary', true)->firstOrFail();
    $copied_people = BoardColumn::where('board_id', $copy_id)->where('key', 'owner')->firstOrFail();

    expect($copied_workload->workload_config['source_view_id'])->toBe($copied_primary->id)
        ->and($copied_workload->workload_config['people_column_id'])->toBe((string) $copied_people->id);
});

// ---- template center -------------------------------------------------------

test('the template center lists the built in templates with a preview', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->getJson('/api/board-templates')->assertOk();

    $ids = collect($response->json('templates'))->pluck('id');
    expect($ids)->toContain('builtin:project_management', 'builtin:sales_pipeline')
        ->and(collect($response->json('templates'))->firstWhere('id', 'builtin:project_management')['preview']['columns'])->not->toBeEmpty();
});

test('a board can be created from a built in template', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $board_id = $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/navigation/from-template", ['template_id' => 'builtin:project_management', 'label' => 'Website redesign'])
        ->assertCreated()
        ->assertJsonPath('item.label', 'Website redesign')
        ->json('item.id');

    $primary = BoardView::where('board_id', $board_id)->where('is_primary', true)->firstOrFail();
    $dashboard = BoardView::where('board_id', $board_id)->where('view_type', 'dashboard')->firstOrFail();
    $status = BoardColumn::where('board_view_id', $primary->id)->where('key', 'status')->firstOrFail();
    $battery = collect($dashboard->dashboard_config['widgets'])->firstWhere('type', 'battery');

    expect(BoardItem::where('board_id', $board_id)->count())->toBeGreaterThan(3)
        ->and($battery['source_view_id'])->toBe($primary->id)
        ->and($battery['config']['status_column_id'])->toBe((string) $status->id);
});

test('a board can be saved as a template and used again', function () {
    $user = User::factory()->create();
    $data = createRoundSixBoard($user);

    $template_id = $this->actingAs($user, 'api')
        ->postJson('/api/board-templates', ['board_id' => $data['board']->id, 'name' => 'Launch kit', 'include_items' => true])
        ->assertCreated()
        ->json('template.id');

    $board_id = $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$data['workspace']->slug}/navigation/from-template", ['template_id' => $template_id, 'label' => 'Second launch'])
        ->assertCreated()
        ->json('item.id');

    $new_sub = BoardItem::where('board_id', $board_id)->where('name', 'Collect quotes')->firstOrFail();
    $new_tag = BoardTag::where('board_id', $board_id)->where('label', 'Urgent')->firstOrFail();
    $new_tags_column = BoardColumn::where('board_id', $board_id)->where('key', 'tags')->firstOrFail();
    $new_parent = BoardItem::where('board_id', $board_id)->where('name', 'Write brief')->firstOrFail();

    expect($new_sub->parent_id)->toBe($new_parent->id)
        ->and(BoardItemValue::where('item_id', $new_parent->id)->where('column_id', $new_tags_column->id)->value('value'))->toBe([(string) $new_tag->id])
        ->and(BoardTemplate::firstOrFail()->use_count)->toBe(1);
});

test('a template saved without items keeps only the structure', function () {
    $user = User::factory()->create();
    $data = createRoundSixBoard($user);

    $template_id = $this->actingAs($user, 'api')
        ->postJson('/api/board-templates', ['board_id' => $data['board']->id, 'name' => 'Empty launch kit'])
        ->json('template.id');

    $board_id = $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$data['workspace']->slug}/navigation/from-template", ['template_id' => $template_id, 'label' => 'Blank launch'])
        ->json('item.id');

    expect(BoardItem::where('board_id', $board_id)->count())->toBe(0)
        ->and(BoardColumn::where('board_id', $board_id)->count())->toBe(6);
});

test('only the author can delete a saved template', function () {
    $author = User::factory()->create();
    $someone_else = User::factory()->create();
    $data = createRoundSixBoard($author);
    $template_id = $this->actingAs($author, 'api')
        ->postJson('/api/board-templates', ['board_id' => $data['board']->id, 'name' => 'Launch kit'])
        ->json('template.id');
    $numeric_id = (int) str_replace('custom:', '', $template_id);

    $this->actingAs($someone_else, 'api')->deleteJson("/api/board-templates/{$numeric_id}")->assertForbidden();
    $this->actingAs($author, 'api')->deleteJson("/api/board-templates/{$numeric_id}")->assertOk();

    expect(BoardTemplate::count())->toBe(0);
});

// ---- workload --------------------------------------------------------------

test('the workload tab sums each person effort per week', function () {
    $user = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $data = createRoundSixBoard($user);
    $workload = BoardView::factory()->create([
        'board_id' => $data['board']->id,
        'view_type' => 'workload',
        'is_primary' => false,
        'workload_config' => ['effort_column_id' => (string) $data['hours']->id, 'bucket' => 'week'],
    ]);

    $response = $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$data['board']->id}/views/{$workload->id}/workload-data")
        ->assertOk()
        ->assertJsonPath('config.people_column_id', (string) $data['people']->id)
        ->assertJsonPath('config.capacity', 40);

    $row = $response->json('people.0');
    expect($row['person']['name'])->toBe('Ada Lovelace')
        ->and((float) $row['cells'][0]['load'])->toBe(10.0)
        ->and($row['items'])->toHaveCount(2);
});

test('the workload tab spreads a timeline item over the days it spans', function () {
    $user = User::factory()->create();
    $data = createRoundSixBoard($user);
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $data['date']->update(['type' => BoardColumn::TYPE_TIMELINE]);
    BoardItemValue::where('item_id', $data['first']->id)->where('column_id', $data['date']->id)
        ->update(['value' => json_encode($monday->toDateString().'..'.$monday->copy()->addDays(1)->toDateString())]);
    $workload = BoardView::factory()->create([
        'board_id' => $data['board']->id,
        'view_type' => 'workload',
        'is_primary' => false,
        'workload_config' => ['effort_column_id' => (string) $data['hours']->id, 'bucket' => 'day'],
    ]);

    $cells = $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$data['board']->id}/views/{$workload->id}/workload-data?start={$monday->toDateString()}")
        ->assertOk()
        ->json('people.0.cells');

    expect((float) $cells[0]['load'])->toBe(3.0)
        ->and((float) $cells[1]['load'])->toBe(3.0)
        ->and((float) $cells[2]['load'])->toBe(4.0);
});

// ---- dashboard -------------------------------------------------------------

test('the dashboard computes numbers, battery and table widgets', function () {
    $user = User::factory()->create();
    $data = createRoundSixBoard($user);
    $dashboard = BoardView::factory()->create([
        'board_id' => $data['board']->id,
        'view_type' => 'dashboard',
        'is_primary' => false,
        'dashboard_config' => ['widgets' => [
            ['id' => 'a', 'type' => 'numbers', 'config' => ['function' => 'sum', 'column_id' => (string) $data['hours']->id]],
            ['id' => 'b', 'type' => 'battery', 'config' => ['status_column_id' => (string) $data['status']->id]],
            ['id' => 'c', 'type' => 'table', 'config' => ['column_ids' => [(string) $data['status']->id, (string) $data['people']->id]]],
        ]],
    ]);

    $widgets = collect($this->actingAs($user, 'api')
        ->getJson("/api/boards/{$data['board']->id}/views/{$dashboard->id}/dashboard-data")
        ->assertOk()
        ->json('widgets'))->keyBy('id');

    expect((float) $widgets['a']['data']['value'])->toBe(10.0)
        ->and((float) $widgets['b']['data']['done_percent'])->toBe(50.0)
        ->and($widgets['c']['data']['rows'])->toHaveCount(2)
        ->and($widgets['c']['data']['rows'][0]['cells'][0]['text'])->toBe('Done');
});

test('a dashboard widget reading a private board the viewer cannot open reports an error', function () {
    $viewer = User::factory()->create();
    $stranger = User::factory()->create();
    $data = createRoundSixBoard($viewer);
    $private = createRoundSixBoard($stranger);
    $private['board']->update(['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE]);

    $dashboard = BoardView::factory()->create([
        'board_id' => $data['board']->id,
        'view_type' => 'dashboard',
        'is_primary' => false,
        'dashboard_config' => ['widgets' => [
            ['id' => 'a', 'type' => 'numbers', 'source_board_id' => $private['board']->id, 'config' => ['function' => 'count']],
        ]],
    ]);

    $this->actingAs($viewer, 'api')
        ->getJson("/api/boards/{$data['board']->id}/views/{$dashboard->id}/dashboard-data")
        ->assertOk()
        ->assertJsonPath('widgets.0.data', null)
        ->assertJsonPath('widgets.0.error', 'This board is not available to you anymore.');
});

test('dashboard data is only served for dashboard tabs', function () {
    $user = User::factory()->create();
    $data = createRoundSixBoard($user);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$data['board']->id}/views/{$data['view']->id}/dashboard-data")
        ->assertStatus(422);
});
