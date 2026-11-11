<?php

use App\Events\BoardAutomationsRan;
use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Facades\Event;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup, 3: BoardGroup, 4: BoardColumn, 5: BoardItem, 6: User}
 */
function createEffectsTestBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'To do']);
    $done_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Completed']);
    $status = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'type' => BoardColumn::TYPE_STATUS,
        'label' => 'Status',
        'config' => ['options' => [
            ['id' => 'working', 'label' => 'Working on it', 'color' => '#fdab3d'],
            ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
        ]],
    ]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Ship the homepage', 'position' => 0]);
    $actor = User::factory()->create();
    $actor->workspaces()->attach($workspace->id);

    return [$board, $view, $group, $done_group, $status, $item, $actor];
}

function createMoveWhenDoneAutomation(WorkspaceNavigationItem $board, BoardView $view, BoardColumn $status, BoardGroup $done_group): BoardAutomation
{
    $actions = [['type' => BoardAutomation::ACTION_MOVE_TO_GROUP, 'params' => ['target_group_id' => $done_group->id]]];

    return BoardAutomation::create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'name' => 'Move finished work',
        'is_enabled' => true,
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'action_type' => $actions[0]['type'],
        'action_params' => $actions[0]['params'],
        'actions' => $actions,
    ]);
}

test('the response of the change that set an automation off carries what the automation changed', function () {
    Event::fake([BoardAutomationsRan::class]);
    [$board, $view, , $done_group, $status, $item, $actor] = createEffectsTestBoard();
    createMoveWhenDoneAutomation($board, $view, $status, $done_group);

    $response = $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $status->id => 'done'],
    ])->assertOk();

    expect($item->fresh()->group_id)->toBe($done_group->id);
    $response->assertJsonPath('automation_effects.boards.0.board_id', $board->id)
        ->assertJsonPath('automation_effects.boards.0.item_ids', [$item->id])
        ->assertJsonPath('automation_effects.runs.0.automation_name', 'Move finished work')
        ->assertJsonPath('automation_effects.runs.0.status', 'success')
        ->assertJsonPath('automation_effects.runs.0.message', 'Moved the item to "Completed".');
    expect($response->json('automation_effects.runs.0.run_log_id'))->toBeInt();

    Event::assertDispatched(BoardAutomationsRan::class, fn (BoardAutomationsRan $event) => $event->board_id === $board->id
        && $event->item_ids === [$item->id]
        && $event->actor_id === $actor->id
        && in_array($event->broadcast_id, $response->json('automation_effects.broadcast_ids'), true));
});

test('a change that sets no automation off has no automation effects', function () {
    Event::fake([BoardAutomationsRan::class]);
    [$board, $view, , $done_group, $status, $item, $actor] = createEffectsTestBoard();
    createMoveWhenDoneAutomation($board, $view, $status, $done_group);

    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $status->id => 'working'],
    ])->assertOk()->assertJsonMissingPath('automation_effects');

    Event::assertNotDispatched(BoardAutomationsRan::class);
});

test('the items index can be narrowed to the rows an automation changed', function () {
    [$board, $view, $group, , , $item, $actor] = createEffectsTestBoard();
    $board->items()->create(['group_id' => $group->id, 'name' => 'Untouched', 'position' => 1]);
    $archived = $board->items()->create(['group_id' => $group->id, 'name' => 'Archived', 'position' => 2, 'is_archived' => true]);

    $response = $this->actingAs($actor, 'api')
        ->getJson("/api/boards/{$board->id}/items?view_id={$view->id}&item_ids[]={$item->id}&item_ids[]={$archived->id}")
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$item->id]);
});
