<?php

use App\Jobs\SendEmailJob;
use App\Mail\Notifications\AssignedNotificationEmail;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Facades\Queue;

function createPeopleAssignmentTestBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_PEOPLE]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Redesign homepage', 'position' => 0]);

    return [$board, $group, $column, $item];
}

test('assigning a new person on a people column creates a notification linked to the item and queues the assigned email', function () {
    Queue::fake();

    [$board, , $column, $item] = createPeopleAssignmentTestBoard();
    $actor = User::factory()->create();
    $assignee = User::factory()->create();

    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $column->id => [$assignee->id]],
    ])->assertOk();

    $this->assertDatabaseHas('notifications', [
        'user_id' => $assignee->id,
        'actor_id' => $actor->id,
        'type' => Notification::TYPE_ASSIGNED,
        'board_id' => $board->id,
        'board_item_id' => $item->id,
    ]);

    Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) use ($assignee) {
        return $job->recipientEmail === $assignee->email && $job->mailable instanceof AssignedNotificationEmail;
    });
});

test('assigning yourself on a people column does not notify', function () {
    Queue::fake();

    [$board, , $column, $item] = createPeopleAssignmentTestBoard();
    $actor = User::factory()->create();

    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $column->id => [$actor->id]],
    ])->assertOk();

    $this->assertDatabaseMissing('notifications', ['user_id' => $actor->id, 'type' => Notification::TYPE_ASSIGNED]);
    Queue::assertNotPushed(SendEmailJob::class);
});

test('re-saving the same people already on the column does not re-notify them', function () {
    Queue::fake();

    [$board, , $column, $item] = createPeopleAssignmentTestBoard();
    $actor = User::factory()->create();
    $assignee = User::factory()->create();
    $item->values()->create(['column_id' => $column->id, 'value' => [$assignee->id]]);

    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $column->id => [$assignee->id]],
    ])->assertOk();

    Queue::assertNotPushed(SendEmailJob::class);
    expect(Notification::where('user_id', $assignee->id)->count())->toBe(0);
});

test('a people column with notify_on_assignment disabled never notifies newly assigned people', function () {
    Queue::fake();

    [$board, , $column, $item] = createPeopleAssignmentTestBoard();
    $column->update(['config' => ['notify_on_assignment' => false]]);
    $actor = User::factory()->create();
    $assignee = User::factory()->create();

    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $column->id => [$assignee->id]],
    ])->assertOk();

    $this->assertDatabaseMissing('notifications', ['user_id' => $assignee->id, 'type' => Notification::TYPE_ASSIGNED]);
    Queue::assertNotPushed(SendEmailJob::class);
});

test('the notify_on_assignment column preference can be toggled through the column update endpoint', function () {
    [$board, , $column] = createPeopleAssignmentTestBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$column->id}", ['config' => ['notify_on_assignment' => false]])
        ->assertOk()
        ->assertJsonPath('column.config.notify_on_assignment', false);

    $this->assertDatabaseHas('board_columns', ['id' => $column->id]);
    expect($column->fresh()->config)->toBe(['notify_on_assignment' => false]);
});
