<?php

use App\Jobs\SendEmailJob;
use App\Mail\Automations\AutomationDigestEmail;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\BoardView;
use App\Models\FeedFollow;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardAutomationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Round five of the Automations center: the subitem column, mention, reply and keyword triggers,
 * dynamic values, the subscriber, subitem and digest actions, and the account wide center.
 *
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup, 3: BoardColumn, 4: Workspace}
 */
function roundFiveBoard(?Workspace $workspace = null, array $board_attributes = []): array
{
    $workspace ??= Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null, ...$board_attributes]);
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Sprint 1']);
    $status = roundFiveColumn($board, $view, BoardColumn::TYPE_STATUS, 'Status', ['options' => [
        ['id' => 'working', 'label' => 'Working on it', 'color' => '#fdab3d'],
        ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
    ]]);

    return [$board, $view, $group, $status, $workspace];
}

function roundFiveColumn(WorkspaceNavigationItem $board, BoardView $view, string $type, string $label, array $config = [], string $scope = BoardColumn::SCOPE_ITEM): BoardColumn
{
    return BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => $type, 'label' => $label, 'scope' => $scope, 'config' => $config ?: null]);
}

function roundFiveAutomation(WorkspaceNavigationItem $board, BoardView $view, array $attributes): BoardAutomation
{
    $actions = $attributes['actions'];

    return BoardAutomation::create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'is_enabled' => true,
        'action_type' => $actions[0]['type'],
        'action_params' => $actions[0]['params'],
        ...$attributes,
    ]);
}

function roundFiveItem(WorkspaceNavigationItem $board, BoardGroup $group, string $name, ?BoardItem $parent = null, ?User $creator = null): BoardItem
{
    return $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent?->id, 'name' => $name, 'position' => 0, 'created_by_id' => $creator?->id]);
}

function roundFiveSet(User $actor, WorkspaceNavigationItem $board, BoardItem $item, BoardColumn $column, mixed $value): void
{
    test()->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $column->id => $value],
    ])->assertOk();
}

function roundFiveValue(BoardItem $item, BoardColumn $column): mixed
{
    return BoardItemValue::where('item_id', $item->id)->where('column_id', $column->id)->first()?->value;
}

function roundFiveMember(Workspace $workspace, string $role = 'member'): User
{
    $user = User::factory()->create();
    $user->workspaces()->attach($workspace->id, ['role' => $role]);

    return $user;
}

// ── Triggers ──────────────────────────────────────────────────────────────────

test('a subitem column change runs the actions on the parent, or on the subitem when asked', function () {
    [$board, $view, $group, $status] = roundFiveBoard();
    $actor = User::factory()->create();
    $stage = roundFiveColumn($board, $view, BoardColumn::TYPE_STATUS, 'Stage', ['options' => [['id' => 'ready', 'label' => 'Ready'], ['id' => 'blocked', 'label' => 'Blocked']]], BoardColumn::SCOPE_SUBITEM);
    $flag = roundFiveColumn($board, $view, BoardColumn::TYPE_TEXT, 'Flag', [], BoardColumn::SCOPE_SUBITEM);
    $parent = roundFiveItem($board, $group, 'Launch');
    $subitem = roundFiveItem($board, $group, 'Copy review', $parent);

    roundFiveAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_SUBITEM_COLUMN_CHANGED,
        'trigger_column_id' => $stage->id,
        'trigger_config' => ['run_on' => 'parent', 'match' => ['operator' => 'is', 'values' => ['blocked']]],
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $status->id, 'value' => 'working']]],
    ]);
    roundFiveAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_SUBITEM_COLUMN_CHANGED,
        'trigger_column_id' => $stage->id,
        'trigger_config' => ['run_on' => 'subitem'],
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $flag->id, 'value' => 'touched']]],
    ]);

    roundFiveSet($actor, $board, $subitem, $stage, 'ready');
    expect(roundFiveValue($parent, $status))->toBeNull()
        ->and(roundFiveValue($subitem, $flag))->toBe('touched');

    roundFiveSet($actor, $board, $subitem, $stage, 'blocked');
    expect(roundFiveValue($parent, $status))->toBe('working');
});

test('a mention fires once per mentioned person and can assign the mentioned person', function () {
    [$board, $view, $group, $status] = roundFiveBoard();
    $author = User::factory()->create();
    [$ada, $grace] = User::factory()->count(2)->create();
    $owner = roundFiveColumn($board, $view, BoardColumn::TYPE_PEOPLE, 'Owner');
    $item = roundFiveItem($board, $group, 'Brief');

    $automation = roundFiveAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_USER_MENTIONED,
        'trigger_value' => null,
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $owner->id, 'value' => null, 'dynamic_value' => ['source' => 'mentioned']]]],
    ]);

    $this->actingAs($author, 'api')->postJson("/api/boards/{$board->id}/items/{$item->id}/comments", [
        'body' => 'Can you two take a look?',
        'mentioned_user_ids' => [$ada->id, $grace->id],
    ])->assertCreated();

    expect(BoardAutomationRunLog::where('automation_id', $automation->id)->where('status', 'success')->count())->toBe(2)
        ->and(roundFiveValue($item, $owner))->toBe([(string) $grace->id]);
});

test('reply and keyword triggers tell updates and replies apart', function () {
    [$board, $view, $group, $status] = roundFiveBoard();
    $author = User::factory()->create();
    $counter = roundFiveColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Replies');
    $item = roundFiveItem($board, $group, 'Bug');

    $replied = roundFiveAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_UPDATE_REPLIED,
        'actions' => [['type' => 'adjust_number', 'params' => ['target_column_id' => $counter->id, 'amount' => 1]]],
    ]);
    $keyword = roundFiveAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_UPDATE_KEYWORD,
        'trigger_config' => ['keywords' => ['URGENT'], 'include_replies' => false],
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $status->id, 'value' => 'working']]],
    ]);

    $update = $this->actingAs($author, 'api')->postJson("/api/boards/{$board->id}/items/{$item->id}/comments", ['body' => 'Nothing special'])->assertCreated()->json('comment.id');
    $this->actingAs($author, 'api')->postJson("/api/boards/{$board->id}/items/{$item->id}/comments", ['body' => 'This is **urgent**', 'parent_id' => $update])->assertCreated();

    expect(roundFiveValue($item, $counter))->toBe(1)
        ->and(roundFiveValue($item, $status))->toBeNull()
        ->and(BoardAutomationRunLog::where('automation_id', $replied->id)->count())->toBe(1)
        ->and(BoardAutomationRunLog::where('automation_id', $keyword->id)->count())->toBe(0);

    $this->actingAs($author, 'api')->postJson("/api/boards/{$board->id}/items/{$item->id}/comments", ['body' => 'Marking this as urgent'])->assertCreated();
    expect(roundFiveValue($item, $status))->toBe('working');
});

// ── Dynamic values ────────────────────────────────────────────────────────────

test('conditions compare with dynamic values read on every run', function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    [$board, $view, $group, $status] = roundFiveBoard();
    $actor = User::factory()->create();
    $due = roundFiveColumn($board, $view, BoardColumn::TYPE_DATE, 'Due date');
    $budget = roundFiveColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Budget');
    $cost = roundFiveColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Cost');
    $flag = roundFiveColumn($board, $view, BoardColumn::TYPE_TEXT, 'Flag');
    $soon = roundFiveItem($board, $group, 'Soon', null, $actor);
    $later = roundFiveItem($board, $group, 'Later');
    BoardItemValue::insert([
        ['item_id' => $soon->id, 'column_id' => $due->id, 'value' => json_encode('2026-10-06')],
        ['item_id' => $soon->id, 'column_id' => $budget->id, 'value' => json_encode(50)],
        ['item_id' => $soon->id, 'column_id' => $cost->id, 'value' => json_encode(80)],
        ['item_id' => $later->id, 'column_id' => $due->id, 'value' => json_encode('2026-11-30')],
        ['item_id' => $later->id, 'column_id' => $budget->id, 'value' => json_encode(500)],
        ['item_id' => $later->id, 'column_id' => $cost->id, 'value' => json_encode(80)],
    ]);

    roundFiveAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'conditions' => [
            ['column_id' => (string) $due->id, 'condition' => 'before', 'value' => '', 'values' => [], 'dynamic' => ['source' => 'today', 'offset_days' => 3]],
            ['column_id' => (string) $budget->id, 'condition' => 'less_than', 'value' => '', 'values' => [], 'dynamic' => ['source' => 'column', 'column_id' => $cost->id]],
            ['column_id' => '__created_by__', 'condition' => 'is', 'value' => '', 'values' => [], 'dynamic' => ['source' => 'actor']],
        ],
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $flag->id, 'value' => 'over budget and due soon']]],
    ]);

    roundFiveSet($actor, $board, $soon, $status, 'working');
    roundFiveSet($actor, $board, $later, $status, 'working');

    expect(roundFiveValue($soon, $flag))->toBe('over budget and due soon')
        ->and(roundFiveValue($later, $flag))->toBeNull();
    Carbon::setTestNow();
});

test('change column value writes a dynamic date, person or number', function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    [$board, $view, $group, $status] = roundFiveBoard();
    $actor = User::factory()->create();
    $due = roundFiveColumn($board, $view, BoardColumn::TYPE_DATE, 'Due date');
    $owner = roundFiveColumn($board, $view, BoardColumn::TYPE_PEOPLE, 'Owner');
    $budget = roundFiveColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Budget');
    $copy = roundFiveColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Budget copy');
    $item = roundFiveItem($board, $group, 'Plan');
    BoardItemValue::create(['item_id' => $item->id, 'column_id' => $budget->id, 'value' => 42]);

    roundFiveAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'actions' => [
            ['type' => 'set_column_value', 'params' => ['target_column_id' => $due->id, 'value' => null, 'dynamic_value' => ['source' => 'today', 'offset_days' => 7]]],
            ['type' => 'set_column_value', 'params' => ['target_column_id' => $owner->id, 'value' => null, 'dynamic_value' => ['source' => 'actor']]],
            ['type' => 'set_column_value', 'params' => ['target_column_id' => $copy->id, 'value' => null, 'dynamic_value' => ['source' => 'column', 'column_id' => $budget->id]]],
        ],
    ]);

    roundFiveSet($actor, $board, $item, $status, 'done');

    expect(roundFiveValue($item, $due))->toBe('2026-10-12')
        ->and(roundFiveValue($item, $owner))->toBe([(string) $actor->id])
        ->and(roundFiveValue($item, $copy))->toBe(42);
    Carbon::setTestNow();
});

test('a dynamic condition saved through the API is kept and applied', function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    [$board, $view, $group, $status] = roundFiveBoard();
    $user = User::factory()->create();
    $due = roundFiveColumn($board, $view, BoardColumn::TYPE_DATE, 'Due date');
    $flag = roundFiveColumn($board, $view, BoardColumn::TYPE_TEXT, 'Flag');
    $soon = roundFiveItem($board, $group, 'Soon');
    $undated = roundFiveItem($board, $group, 'Undated');
    BoardItemValue::create(['item_id' => $soon->id, 'column_id' => $due->id, 'value' => '2026-10-06']);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'trigger_type' => 'status_changed',
        'trigger_column_id' => $status->id,
        'conditions' => [['column_id' => (string) $due->id, 'condition' => 'before', 'value' => '', 'values' => [], 'dynamic' => ['source' => 'today', 'offset_days' => 3, 'use_working_days' => false]]],
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $flag->id, 'value' => 'due soon']]],
    ])->assertCreated()->assertJsonPath('automation.conditions.0.dynamic', ['source' => 'today', 'offset_days' => 3]);

    roundFiveSet($user, $board, $soon, $status, 'working');
    roundFiveSet($user, $board, $undated, $status, 'working');

    expect(roundFiveValue($soon, $flag))->toBe('due soon')
        ->and(roundFiveValue($undated, $flag))->toBeNull();
    Carbon::setTestNow();
});

test('a dynamic value that does not fit its column is rejected', function () {
    [$board, $view, $group, $status] = roundFiveBoard();
    $user = User::factory()->create();
    $budget = roundFiveColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Budget');
    $due = roundFiveColumn($board, $view, BoardColumn::TYPE_DATE, 'Due date');

    $payload = fn (array $overrides) => [
        'view_id' => $view->id,
        'trigger_type' => 'status_changed',
        'trigger_column_id' => $status->id,
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $budget->id, 'value' => null, 'dynamic_value' => ['source' => 'today']]]],
        ...$overrides,
    ];

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", $payload([]))
        ->assertUnprocessable()->assertJsonValidationErrors('actions.0.params.dynamic_value');

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", $payload([
        'actions' => [['type' => 'archive_item', 'params' => []]],
        'conditions' => [['column_id' => (string) $due->id, 'condition' => 'between', 'value' => '', 'values' => [], 'dynamic' => ['source' => 'today']]],
    ]))->assertUnprocessable()->assertJsonValidationErrors('conditions.0.dynamic');

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", $payload([
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $due->id, 'value' => null, 'dynamic_value' => ['source' => 'column', 'column_id' => $due->id, 'offset_days' => 2]]]],
    ]))->assertCreated();
});

test('new triggers validate their column and keywords', function () {
    [$board, $view, $group, $status] = roundFiveBoard();
    $user = User::factory()->create();
    $archive = [['type' => 'archive_item', 'params' => []]];

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", ['view_id' => $view->id, 'trigger_type' => 'subitem_column_changed', 'trigger_column_id' => $status->id, 'actions' => $archive])
        ->assertUnprocessable()->assertJsonValidationErrors('trigger_column_id');
    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", ['view_id' => $view->id, 'trigger_type' => 'update_keyword', 'trigger_config' => ['keywords' => []], 'actions' => $archive])
        ->assertUnprocessable()->assertJsonValidationErrors('trigger_config.keywords');
    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", ['view_id' => $view->id, 'trigger_type' => 'user_mentioned', 'trigger_value' => 999999, 'actions' => $archive])
        ->assertUnprocessable()->assertJsonValidationErrors('trigger_value');
});

// ── People, subitems and digests ──────────────────────────────────────────────

test('subscribe, notify and unsubscribe the item subscribers', function () {
    [$board, $view, $group, $status, $workspace] = roundFiveBoard();
    $creator = roundFiveMember($workspace);
    $teammate = roundFiveMember($workspace);
    $outsider = User::factory()->create();
    $actor = roundFiveMember($workspace);
    $item = roundFiveItem($board, $group, 'Proposal', null, $creator);

    $subscribe = roundFiveAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'working',
        'actions' => [
            ['type' => 'subscribe_people', 'params' => ['user_ids' => [$teammate->id, $outsider->id], 'recipient_source' => 'creator']],
            ['type' => 'notify_subscribers', 'params' => ['message' => '{item_name} moved']],
        ],
    ]);
    roundFiveSet($actor, $board, $item, $status, 'working');

    $followers = FeedFollow::where('target_type', FeedFollow::TYPE_ITEM)->where('target_id', $item->id)->pluck('user_id')->sort()->values()->all();
    expect($followers)->toBe(collect([$creator->id, $teammate->id])->sort()->values()->all())
        ->and(BoardAutomationRunLog::where('automation_id', $subscribe->id)->where('action_type', 'notify_subscribers')->value('message'))->toBe('Notified the 2 subscriber(s) of the item.');

    roundFiveAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [['type' => 'unsubscribe_people', 'params' => ['everyone' => true]]],
    ]);
    roundFiveSet($actor, $board, $item, $status, 'done');
    expect(FeedFollow::where('target_type', FeedFollow::TYPE_ITEM)->where('target_id', $item->id)->count())->toBe(0);
});

test('subitems can be archived together, turned into items, and created from a list', function () {
    [$board, $view, $group, $status] = roundFiveBoard();
    $actor = User::factory()->create();
    $sub_status = roundFiveColumn($board, $view, BoardColumn::TYPE_STATUS, 'Status', ['options' => [['id' => 'done', 'label' => 'Done']]], BoardColumn::SCOPE_SUBITEM);
    $notes = roundFiveColumn($board, $view, BoardColumn::TYPE_LONG_TEXT, 'Steps');
    $parent = roundFiveItem($board, $group, 'Release');
    $first = roundFiveItem($board, $group, 'Old step', $parent);
    $second = roundFiveItem($board, $group, 'Another old step', $parent);
    BoardItemValue::create(['item_id' => $parent->id, 'column_id' => $notes->id, 'value' => "Write notes\nTag build\n"]);

    roundFiveAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'working',
        'actions' => [
            ['type' => 'clear_subitems', 'params' => ['operation' => 'archive']],
            ['type' => 'create_subitem', 'params' => ['subitem_names' => ['Check {item_name}'], 'source_column_id' => $notes->id]],
        ],
    ]);
    roundFiveSet($actor, $board, $parent, $status, 'working');

    expect($first->fresh()->is_archived)->toBeTrue()
        ->and($second->fresh()->is_archived)->toBeTrue()
        ->and(BoardItem::where('parent_id', $parent->id)->where('is_archived', false)->orderBy('position')->pluck('name')->all())->toBe(['Check Release', 'Write notes', 'Tag build']);

    $second->update(['is_archived' => false]);
    roundFiveAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_SUBITEM_COLUMN_CHANGED,
        'trigger_column_id' => $sub_status->id,
        'trigger_config' => ['run_on' => 'subitem'],
        'actions' => [['type' => 'convert_subitem', 'params' => []]],
    ]);
    roundFiveSet($actor, $board, $second, $sub_status, 'done');

    // The subitem's Status is copied into the item's Status, the columns share a label and a type.
    $converted = $second->fresh();
    expect($converted->parent_id)->toBeNull()
        ->and($converted->group_id)->toBe($group->id)
        ->and(roundFiveValue($converted, $status))->toBe('done');
});

test('a digest emails the matching items with the chosen columns', function () {
    Queue::fake();
    [$board, $view, $group, $status, $workspace] = roundFiveBoard();
    $actor = User::factory()->create();
    $reader = User::factory()->create();
    $due = roundFiveColumn($board, $view, BoardColumn::TYPE_DATE, 'Due date');
    $open = roundFiveItem($board, $group, 'Open task');
    $finished = roundFiveItem($board, $group, 'Finished task');
    $trigger_item = roundFiveItem($board, $group, 'Trigger');
    BoardItemValue::create(['item_id' => $open->id, 'column_id' => $due->id, 'value' => '2026-10-09']);
    BoardItemValue::create(['item_id' => $finished->id, 'column_id' => $status->id, 'value' => 'done']);
    BoardItemValue::create(['item_id' => $trigger_item->id, 'column_id' => $status->id, 'value' => 'done']);

    roundFiveAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_NAME_CHANGED,
        'actions' => [['type' => 'send_digest', 'params' => [
            'user_ids' => [$reader->id],
            'subject' => 'Open work on {board_name}',
            'column_ids' => [$due->id],
            'digest_rules' => [['column_id' => (string) $status->id, 'condition' => 'is_not', 'value' => '', 'values' => ['done']]],
        ]]],
    ]);

    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$trigger_item->id}", ['name' => 'Trigger renamed'])->assertOk();

    Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) use ($reader) {
        $mailable = $job->mailable;
        $recipient = $job->recipientEmail;

        return $mailable instanceof AutomationDigestEmail
            && $recipient === $reader->email
            && $mailable->total === 1
            && $mailable->rows[0]['name'] === 'Open task'
            && $mailable->rows[0]['values'] === ['2026-10-09']
            && $mailable->columns === ['Due date'];
    });
});

test('a recurring digest saves with the empty otherwise list the builder sends', function () {
    [$board, $view, $group, $status] = roundFiveBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'trigger_type' => 'recurring',
        'trigger_config' => ['schedule' => ['frequency' => 'weekly', 'weekdays' => [1], 'time' => '09:00']],
        'conditions' => [],
        'condition_groups' => [],
        'else_actions' => [],
        'actions' => [['type' => 'send_digest', 'params' => [
            'user_ids' => [$user->id],
            'column_ids' => [$status->id],
            'digest_rules' => [['column_id' => (string) $status->id, 'condition' => 'is_not', 'value' => '', 'values' => ['done']]],
        ]]],
    ])->assertCreated();
});

test('a digest needs someone to send it to', function () {
    [$board, $view, $group, $status] = roundFiveBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'trigger_type' => 'recurring',
        'trigger_config' => ['schedule' => ['frequency' => 'daily', 'time' => '09:00']],
        'actions' => [['type' => 'send_digest', 'params' => ['column_ids' => [], 'digest_rules' => []]]],
    ])->assertUnprocessable()->assertJsonValidationErrors('actions.0.params.user_ids');
});

// ── Account wide center ───────────────────────────────────────────────────────

test('the account center lists visible automations and toggles only editable ones', function () {
    $workspace = Workspace::factory()->create();
    $user = roundFiveMember($workspace);
    [$open_board, $open_view, , $open_status] = roundFiveBoard($workspace);
    [$locked_board, $locked_view, , $locked_status] = roundFiveBoard($workspace, ['edit_permission' => 'view_only']);
    [$private_board, $private_view, , $private_status] = roundFiveBoard($workspace, ['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE, 'created_by_id' => User::factory()->create()->id]);

    $archive = [['type' => 'archive_item', 'params' => []]];
    $editable = roundFiveAutomation($open_board, $open_view, ['name' => 'Editable', 'trigger_type' => 'status_changed', 'trigger_column_id' => $open_status->id, 'actions' => $archive]);
    $locked = roundFiveAutomation($locked_board, $locked_view, ['name' => 'Locked', 'trigger_type' => 'status_changed', 'trigger_column_id' => $locked_status->id, 'actions' => $archive]);
    roundFiveAutomation($private_board, $private_view, ['name' => 'Hidden', 'trigger_type' => 'status_changed', 'trigger_column_id' => $private_status->id, 'actions' => $archive]);

    $response = $this->actingAs($user, 'api')->getJson('/api/automations')->assertOk();
    $rows = collect($response->json('data'))->keyBy('name');
    expect($rows->keys()->sort()->values()->all())->toBe(['Editable', 'Locked'])
        ->and($rows['Editable']['can_edit'])->toBeTrue()
        ->and($rows['Locked']['can_edit'])->toBeFalse()
        ->and($rows['Editable']['board']['label'])->toBe($open_board->label)
        ->and($response->json('summary.total'))->toBe(2);

    $this->actingAs($user, 'api')->postJson('/api/automations/bulk', ['automation_ids' => [$editable->id, $locked->id], 'action' => 'disable'])
        ->assertOk()
        ->assertJsonPath('affected_ids', [$editable->id])
        ->assertJsonCount(1, 'skipped');

    expect($editable->fresh()->is_enabled)->toBeFalse()
        ->and($locked->fresh()->is_enabled)->toBeTrue();
});

test('the service matches keywords without case', function () {
    $service = app(BoardAutomationService::class);

    expect($service->matchedKeyword('Please ESCALATE this', ['urgent', 'escalate']))->toBe('escalate')
        ->and($service->matchedKeyword('all good', ['urgent']))->toBeNull();
});
