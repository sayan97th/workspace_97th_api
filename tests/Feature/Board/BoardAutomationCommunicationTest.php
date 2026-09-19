<?php

use App\Jobs\SendEmailJob;
use App\Jobs\SendSlackMessageJob;
use App\Mail\Automations\AutomationEmail;
use App\Models\BoardActivityLog;
use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardAutomationService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['app.frontend_url' => 'http://frontend.test']);
});

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup, 3: BoardItem}
 */
function createCommunicationTestBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        'label' => 'Launch board',
    ]);
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Ship the homepage', 'position' => 0]);

    return [$board, $view, $group, $item];
}

function createCommunicationAutomation(WorkspaceNavigationItem $board, BoardView $view, array $overrides): BoardAutomation
{
    return BoardAutomation::create(array_merge([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'is_enabled' => true,
        'trigger_column_id' => null,
    ], $overrides));
}

function createStatusColumn(WorkspaceNavigationItem $board, BoardView $view): BoardColumn
{
    return BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'type' => BoardColumn::TYPE_STATUS,
        'label' => 'Status',
        'config' => ['options' => [
            ['id' => 'working', 'label' => 'Working on it', 'color' => '#fdab3d'],
            ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
        ]],
    ]);
}

function slackInstallationWithLink(User $user, string $slack_user_id = 'UAMANDA'): SlackInstallation
{
    $installation = SlackInstallation::current() ?? SlackInstallation::create([
        'team_id' => 'T100', 'team_name' => 'Acme', 'bot_token' => 'xoxb-secret-token',
    ]);
    SlackUserLink::create(['user_id' => $user->id, 'slack_installation_id' => $installation->id, 'slack_user_id' => $slack_user_id]);

    return $installation;
}

test('a status_changed automation emails the configured person with the templated message', function () {
    Queue::fake();
    [$board, $view, , $item] = createCommunicationTestBoard();
    $status_column = createStatusColumn($board, $view);
    $recipient = User::factory()->create(['email' => 'amanda@example.com']);
    $actor = User::factory()->create();

    createCommunicationAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status_column->id,
        'trigger_value' => 'done',
        'action_type' => BoardAutomation::ACTION_SEND_EMAIL,
        'action_params' => [
            'notify_user_id' => $recipient->id,
            'subject' => 'Finished: {item_name}',
            'message' => '{item_name} on {board_name} is now {new_value} (was {old_value}).',
        ],
    ]);

    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $status_column->id => 'working'],
    ])->assertOk();
    Queue::assertNotPushed(SendEmailJob::class);

    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $status_column->id => 'done'],
    ])->assertOk();

    Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) use ($item, $board) {
        return $job->recipientEmail === 'amanda@example.com'
            && $job->mailable instanceof AutomationEmail
            && $job->mailable->email_subject === 'Finished: Ship the homepage'
            && $job->mailable->message_body === 'Ship the homepage on Launch board is now Done (was Working on it).'
            && $job->mailable->cta_path === "/boards/{$board->id}/pulses/{$item->id}";
    });

    $this->assertDatabaseHas('board_activity_logs', ['board_id' => $board->id, 'action' => BoardActivityLog::ACTION_AUTOMATION_RAN]);
});

test('an email automation reaches everyone in the people column and skips deactivated accounts', function () {
    Queue::fake();
    [$board, $view, , $item] = createCommunicationTestBoard();
    $people_column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_PEOPLE]);
    $first = User::factory()->create();
    $second = User::factory()->create();
    $inactive = User::factory()->create(['is_active' => false]);
    $item->values()->create(['column_id' => $people_column->id, 'value' => [$first->id, $second->id, $inactive->id]]);

    createCommunicationAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED,
        'action_type' => BoardAutomation::ACTION_SEND_EMAIL,
        'action_params' => ['notify_from_people_column_id' => $people_column->id],
    ]);

    $group = $item->group;
    $this->actingAs(User::factory()->create(), 'api')->postJson("/api/boards/{$board->id}/items", [
        'group_id' => $group->id,
        'name' => 'Second item',
    ])->assertCreated();
    // The people column is empty on the brand new item, so nobody is emailed yet.
    Queue::assertNotPushed(SendEmailJob::class);

    $new_item = BoardItem::where('name', 'Second item')->first();
    $new_item->values()->create(['column_id' => $people_column->id, 'value' => [$first->id, $second->id, $inactive->id]]);
    app(BoardAutomationService::class)->handleItemCreated($new_item, null);

    Queue::assertPushed(SendEmailJob::class, 2);
    Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipientEmail === $first->email);
    Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipientEmail === $second->email);
    Queue::assertNotPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipientEmail === $inactive->email);
});

test('a slack channel automation posts the templated message to the channel', function () {
    Queue::fake();
    [$board, $view, $group] = createCommunicationTestBoard();
    SlackInstallation::create(['team_id' => 'T100', 'team_name' => 'Acme', 'bot_token' => 'xoxb-secret-token']);

    createCommunicationAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED,
        'action_type' => BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL,
        'action_params' => ['slack_channel_id' => 'C123', 'slack_channel_name' => 'launches', 'message' => 'New item <!channel> "{item_name}"'],
    ]);

    $this->actingAs(User::factory()->create(), 'api')->postJson("/api/boards/{$board->id}/items", [
        'group_id' => $group->id,
        'name' => 'Brand new task',
    ])->assertCreated();

    Queue::assertPushed(SendSlackMessageJob::class, function (SendSlackMessageJob $job) {
        return $job->channel === 'C123'
            && $job->text === 'New item <!channel> "Brand new task"'
            // Markup in the template is escaped, so it can never broadcast to the channel.
            && $job->blocks[0]['text']['text'] === 'New item &lt;!channel&gt; "Brand new task"';
    });

    $this->assertDatabaseHas('board_activity_logs', [
        'board_id' => $board->id,
        'description' => 'Automation "Automation" posted to Slack channel #launches',
    ]);
});

test('a slack person automation messages linked members and logs those who are not linked', function () {
    Queue::fake();
    [$board, $view, , $item] = createCommunicationTestBoard();
    $people_column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_PEOPLE]);
    $linked = User::factory()->create(['first_name' => 'Amanda', 'last_name' => 'Diaz']);
    $unlinked = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Stone']);
    slackInstallationWithLink($linked, 'UAMANDA');
    $item->values()->create(['column_id' => $people_column->id, 'value' => [$linked->id, $unlinked->id]]);

    createCommunicationAutomation($board, $view, [
        'name' => 'Ping owners',
        'trigger_type' => BoardAutomation::TRIGGER_UPDATE_POSTED,
        'action_type' => BoardAutomation::ACTION_SLACK_NOTIFY_PERSON,
        'action_params' => ['notify_from_people_column_id' => $people_column->id],
    ]);

    $this->actingAs(User::factory()->create(['first_name' => 'Ernesto', 'last_name' => 'Afane']), 'api')
        ->postJson("/api/boards/{$board->id}/items/{$item->id}/comments", ['body' => 'Copy is approved'])
        ->assertCreated();

    Queue::assertPushed(SendSlackMessageJob::class, 1);
    Queue::assertPushed(SendSlackMessageJob::class, fn (SendSlackMessageJob $job) => $job->channel === 'UAMANDA'
        && $job->text === 'Ernesto Afane posted an update on "Ship the homepage": Copy is approved');

    $description = BoardActivityLog::where('board_id', $board->id)->where('action', BoardActivityLog::ACTION_AUTOMATION_RAN)->value('description');
    expect($description)
        ->toContain('sent a Slack message to Amanda Diaz')
        ->toContain('could not reach Bob Stone on Slack');
});

test('an update_posted automation ignores replies', function () {
    Queue::fake();
    [$board, $view, , $item] = createCommunicationTestBoard();
    SlackInstallation::create(['team_id' => 'T100', 'team_name' => 'Acme', 'bot_token' => 'xoxb-secret-token']);
    createCommunicationAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_UPDATE_POSTED,
        'action_type' => BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL,
        'action_params' => ['slack_channel_id' => 'C123'],
    ]);
    $author = User::factory()->create();

    $parent_id = $this->actingAs($author, 'api')
        ->postJson("/api/boards/{$board->id}/items/{$item->id}/comments", ['body' => 'First'])
        ->assertCreated()->json('comment.id');
    Queue::assertPushed(SendSlackMessageJob::class, 1);

    $this->actingAs($author, 'api')
        ->postJson("/api/boards/{$board->id}/items/{$item->id}/comments", ['body' => 'A reply', 'parent_id' => $parent_id])
        ->assertCreated();
    Queue::assertPushed(SendSlackMessageJob::class, 1);
});

test('a column_changed automation fires for any editable column only when the value really changes', function () {
    Queue::fake();
    [$board, $view, , $item] = createCommunicationTestBoard();
    $text_column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_TEXT, 'label' => 'Notes']);
    $recipient = User::factory()->create();

    createCommunicationAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_COLUMN_CHANGED,
        'trigger_column_id' => $text_column->id,
        'action_type' => BoardAutomation::ACTION_SEND_EMAIL,
        'action_params' => ['notify_user_id' => $recipient->id],
    ]);

    $actor = User::factory()->create();
    $patch = fn (string $value) => $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $text_column->id => $value],
    ])->assertOk();

    $patch('First note');
    Queue::assertPushed(SendEmailJob::class, 1);

    $patch('First note');
    Queue::assertPushed(SendEmailJob::class, 1);

    $patch('Second note');
    Queue::assertPushed(SendEmailJob::class, 2);
    Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->mailable->message_body === 'Notes changed to "Second note" on "Ship the homepage".');
});

test('a disabled communication automation does nothing', function () {
    Queue::fake();
    [$board, $view, $group] = createCommunicationTestBoard();
    createCommunicationAutomation($board, $view, [
        'is_enabled' => false,
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED,
        'action_type' => BoardAutomation::ACTION_SEND_EMAIL,
        'action_params' => ['notify_user_id' => User::factory()->create()->id],
    ]);

    $this->actingAs(User::factory()->create(), 'api')->postJson("/api/boards/{$board->id}/items", [
        'group_id' => $group->id,
        'name' => 'Quiet item',
    ])->assertCreated();

    Queue::assertNotPushed(SendEmailJob::class);
});

test('the automation email renders the message and a link back to the item', function () {
    $html = (new AutomationEmail('Finished: Homepage', "Line one\nLine two", 'Launch board', '/boards/1/pulses/2'))->render();

    expect($html)
        ->toContain('Finished: Homepage')
        ->toContain('Line one')
        ->toContain('Launch board')
        ->toContain('http://frontend.test/boards/1/pulses/2');
});
