<?php

use App\Models\BoardAutomation;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup, 3: BoardItem}
 */
function createManageTestBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        'label' => 'Manage board',
    ]);
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Ship the homepage', 'position' => 0]);

    return [$board, $view, $group, $item];
}

function createManageAutomation(WorkspaceNavigationItem $board, BoardView $view, array $overrides = []): BoardAutomation
{
    return BoardAutomation::create(array_merge([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'is_enabled' => true,
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED,
        'trigger_column_id' => null,
        'action_type' => BoardAutomation::ACTION_NOTIFY_PERSON,
        'action_params' => [],
    ], $overrides));
}

function createRunLog(BoardAutomation $automation, array $overrides = []): BoardAutomationRunLog
{
    $created_at = $overrides['created_at'] ?? null;
    unset($overrides['created_at']);

    $log = BoardAutomationRunLog::create(array_merge([
        'automation_id' => $automation->id,
        'board_id' => $automation->board_id,
        'board_view_id' => $automation->board_view_id,
        'automation_name' => $automation->name,
        'item_name' => 'Ship the homepage',
        'trigger_type' => $automation->trigger_type,
        'action_type' => $automation->action_type,
        'status' => BoardAutomationRunLog::STATUS_SUCCESS,
        'message' => 'Notified someone.',
    ], $overrides));

    // `created_at` is not mass assignable, so a past run is backdated separately.
    if ($created_at) {
        $log->forceFill(['created_at' => $created_at])->save();
    }

    return $log;
}

test('an automation run is recorded in the run history with its outcome', function () {
    Queue::fake();
    [$board, $view, $group] = createManageTestBoard();
    $recipient = User::factory()->create();
    $actor = User::factory()->create();
    $automation = createManageAutomation($board, $view, ['name' => 'Tell Amanda', 'action_params' => ['notify_user_id' => $recipient->id]]);

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$board->id}/items", ['group_id' => $group->id, 'name' => 'A brand new task'])->assertCreated();

    $this->assertDatabaseHas('board_automation_run_logs', [
        'automation_id' => $automation->id,
        'board_view_id' => $view->id,
        'automation_name' => 'Tell Amanda',
        'item_name' => 'A brand new task',
        'status' => BoardAutomationRunLog::STATUS_SUCCESS,
        'actor_id' => $actor->id,
    ]);
});

test('a run with nobody to notify is recorded as skipped', function () {
    Queue::fake();
    [$board, $view, $group] = createManageTestBoard();
    createManageAutomation($board, $view, ['action_params' => ['notify_user_id' => 999999]]);

    $this->actingAs(User::factory()->create(), 'api')->postJson("/api/boards/{$board->id}/items", ['group_id' => $group->id, 'name' => 'Task'])->assertCreated();

    $this->assertDatabaseHas('board_automation_run_logs', ['status' => BoardAutomationRunLog::STATUS_SKIPPED]);
});

test('a slack automation without a connected workspace is recorded as failed', function () {
    Queue::fake();
    [$board, $view, $group] = createManageTestBoard();
    createManageAutomation($board, $view, [
        'action_type' => BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL,
        'action_params' => ['slack_channel_id' => 'C0123456', 'slack_channel_name' => 'general'],
    ]);

    $this->actingAs(User::factory()->create(), 'api')->postJson("/api/boards/{$board->id}/items", ['group_id' => $group->id, 'name' => 'Task'])->assertCreated();

    $this->assertDatabaseHas('board_automation_run_logs', ['status' => BoardAutomationRunLog::STATUS_FAILED, 'action_type' => BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL]);
});

test('the automations list carries the run count, the last run and the creator', function () {
    [$board, $view] = createManageTestBoard();
    $creator = User::factory()->create(['first_name' => 'Amanda', 'last_name' => 'Reyes']);
    $automation = createManageAutomation($board, $view, ['created_by_id' => $creator->id]);
    createRunLog($automation);
    createRunLog($automation);

    $response = $this->actingAs($creator, 'api')->getJson("/api/boards/{$board->id}/automations?view_id={$view->id}")->assertOk();

    $response->assertJsonPath('data.0.run_count', 2)
        ->assertJsonPath('data.0.created_by.name', $creator->full_name);
    expect($response->json('data.0.last_run_at'))->not->toBeNull();
});

test('the run history is paginated, newest first, and can be filtered', function () {
    [$board, $view] = createManageTestBoard();
    $first = createManageAutomation($board, $view, ['name' => 'First']);
    $second = createManageAutomation($board, $view, ['name' => 'Second']);
    createRunLog($first, ['created_at' => now()->subDays(3)]);
    createRunLog($second, ['status' => BoardAutomationRunLog::STATUS_FAILED, 'created_at' => now()->subDay()]);
    createRunLog($second, ['created_at' => now()]);
    $user = User::factory()->create();

    $all = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/automations/runs?view_id={$view->id}&per_page=2")->assertOk();
    expect($all->json('meta.total'))->toBe(3)
        ->and($all->json('meta.last_page'))->toBe(2)
        ->and($all->json('data'))->toHaveCount(2)
        ->and($all->json('data.0.automation_name'))->toBe('Second');

    $failed = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/automations/runs?view_id={$view->id}&status=failed")->assertOk();
    expect($failed->json('data'))->toHaveCount(1);

    $by_automation = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/automations/runs?view_id={$view->id}&automation_id={$first->id}")->assertOk();
    expect($by_automation->json('data'))->toHaveCount(1);

    $by_date = $this->actingAs($user, 'api')->getJson('/api/boards/'.$board->id.'/automations/runs?view_id='.$view->id.'&from='.now()->subDays(2)->toDateString())->assertOk();
    expect($by_date->json('data'))->toHaveCount(2);

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/automations/runs?view_id={$view->id}&status=bogus")->assertUnprocessable();
});

test('the run history survives deleting the automation', function () {
    [$board, $view] = createManageTestBoard();
    $automation = createManageAutomation($board, $view, ['name' => 'Short lived']);
    createRunLog($automation);

    $this->actingAs(User::factory()->create(), 'api')->deleteJson("/api/boards/{$board->id}/automations/{$automation->id}")->assertOk();

    $response = $this->actingAs(User::factory()->create(), 'api')->getJson("/api/boards/{$board->id}/automations/runs?view_id={$view->id}")->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.automation_id'))->toBeNull()
        ->and($response->json('data.0.automation_name'))->toBe('Short lived');
});

test('the usage endpoint sums the last thirty days by outcome, day and automation', function () {
    [$board, $view] = createManageTestBoard();
    $busy = createManageAutomation($board, $view, ['name' => 'Busy']);
    createManageAutomation($board, $view, ['is_enabled' => false]);
    createRunLog($busy);
    createRunLog($busy, ['status' => BoardAutomationRunLog::STATUS_FAILED]);
    createRunLog($busy, ['status' => BoardAutomationRunLog::STATUS_SKIPPED, 'created_at' => now()->subDays(2)]);
    createRunLog($busy, ['created_at' => now()->subDays(60)]);

    $response = $this->actingAs(User::factory()->create(), 'api')->getJson("/api/boards/{$board->id}/automations/usage?view_id={$view->id}")->assertOk();

    $response->assertJsonPath('data.runs', 3)
        ->assertJsonPath('data.success', 1)
        ->assertJsonPath('data.failed', 1)
        ->assertJsonPath('data.skipped', 1)
        ->assertJsonPath('data.automations', 2)
        ->assertJsonPath('data.enabled_automations', 1)
        ->assertJsonPath('data.top_automations.0.automation_name', 'Busy')
        ->assertJsonPath('data.top_automations.0.runs', 3);
    expect($response->json('data.daily'))->toHaveCount(30)
        ->and(collect($response->json('data.daily'))->sum('runs'))->toBe(3);
});

test('duplicating an automation creates a disabled copy', function () {
    [$board, $view] = createManageTestBoard();
    $creator = User::factory()->create();
    $automation = createManageAutomation($board, $view, ['name' => 'Original', 'action_params' => ['notify_user_id' => $creator->id]]);

    $response = $this->actingAs($creator, 'api')->postJson("/api/boards/{$board->id}/automations/{$automation->id}/duplicate")->assertCreated();

    $response->assertJsonPath('automation.name', 'Original (copy)')
        ->assertJsonPath('automation.is_enabled', false)
        ->assertJsonPath('automation.action_params.notify_user_id', $creator->id)
        ->assertJsonPath('automation.run_count', 0);
    expect(BoardAutomation::where('board_id', $board->id)->count())->toBe(2);
});

test('an automation of another board cannot be duplicated', function () {
    [$board, $view] = createManageTestBoard();
    [$other_board, $other_view] = createManageTestBoard();
    $automation = createManageAutomation($other_board, $other_view);

    $this->actingAs(User::factory()->create(), 'api')->postJson("/api/boards/{$board->id}/automations/{$automation->id}/duplicate")->assertNotFound();
});

test('the prune command deletes only run history older than the retention window', function () {
    [$board, $view] = createManageTestBoard();
    $automation = createManageAutomation($board, $view);
    createRunLog($automation, ['created_at' => now()->subDays(120)]);
    createRunLog($automation, ['created_at' => now()->subDays(10)]);

    $this->artisan('automations:prune-run-logs')->assertSuccessful();

    expect(BoardAutomationRunLog::count())->toBe(1);
});
