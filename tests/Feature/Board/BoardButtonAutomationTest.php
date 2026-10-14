<?php

use App\Models\AccountTeam;
use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup, 3: BoardColumn}
 */
function buttonAutomationBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Sprint 1']);
    $button = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'type' => BoardColumn::TYPE_BUTTON,
        'label' => 'Button',
        'scope' => BoardColumn::SCOPE_ITEM,
        'config' => ['button_label' => 'Click me', 'button_color' => '#579bfc'],
    ]);

    return [$board, $view, $group, $button];
}

test('a button automation notifies every chosen person, team member and the clicker once', function () {
    [$board, $view, $group, $button] = buttonAutomationBoard();
    $clicker = User::factory()->create();
    $people = User::factory()->count(2)->create();
    $team = AccountTeam::factory()->create(['name' => 'Designers']);
    $team_member = User::factory()->create();
    // In the team and picked by name, still notified once.
    $team->members()->attach([$team_member->id, $people[0]->id]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Logo', 'position' => 0]);

    $this->actingAs($clicker, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'trigger_type' => BoardAutomation::TRIGGER_BUTTON_CLICKED,
        'trigger_column_id' => $button->id,
        'actions' => [['type' => 'notify_person', 'params' => [
            'notify_user_ids' => $people->pluck('id')->all(),
            'notify_team_ids' => [$team->id],
            'recipient_source' => 'actor',
            'message' => '{item_name} needs a look',
        ]]],
    ])->assertCreated();

    $this->actingAs($clicker, 'api')->postJson("/api/boards/{$board->id}/items/{$item->id}/buttons/{$button->id}")
        ->assertOk()->assertJsonPath('automations_run', 1);

    foreach ([$clicker, $people[0], $people[1], $team_member] as $recipient) {
        expect(Notification::where('user_id', $recipient->id)->count())->toBe(1);
    }
});

test('a notify action needs at least one recipient and only real teams', function () {
    [$board, $view, , $button] = buttonAutomationBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'trigger_type' => BoardAutomation::TRIGGER_BUTTON_CLICKED,
        'trigger_column_id' => $button->id,
        'actions' => [['type' => 'notify_person', 'params' => ['notify_user_ids' => []]]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['actions.0.params.notify_user_id']);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'trigger_type' => BoardAutomation::TRIGGER_BUTTON_CLICKED,
        'trigger_column_id' => $button->id,
        'actions' => [['type' => 'notify_person', 'params' => ['notify_team_ids' => [999999]]]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['actions.0.params.notify_team_ids.0']);
});
