<?php

use App\Jobs\SendSlackInvitationJob;
use App\Jobs\SendSlackMessageJob;
use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Slack\SlackService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    config(['app.frontend_url' => 'http://frontend.test']);
});

function invitationSlackWorkspace(array $overrides = []): SlackInstallation
{
    return SlackInstallation::create(array_merge([
        'team_id' => 'T100',
        'team_name' => 'Acme',
        'bot_user_id' => 'UBOT',
        'bot_token' => 'xoxb-secret-token',
        'scopes' => implode(',', SlackService::BOT_SCOPES),
    ], $overrides));
}

function invitationWorkspaceOwner(Workspace $workspace): User
{
    $owner = User::factory()->create(['first_name' => 'Ernesto', 'last_name' => 'Afane']);
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    return $owner;
}

/**
 * Runs every queued invitation lookup, then returns the Slack messages it queued in turn.
 *
 * @return array<int, SendSlackMessageJob>
 */
function runSlackInvitationJobs(): array
{
    $messages = [];

    Queue::pushed(SendSlackInvitationJob::class)->each(fn (SendSlackInvitationJob $job) => app()->call([$job, 'handle']));

    Queue::pushed(SendSlackMessageJob::class)->each(function (SendSlackMessageJob $job) use (&$messages) {
        $messages[] = $job;
    });

    return $messages;
}

test('a workspace invitation is also sent on slack with an accept button', function () {
    Queue::fake();
    invitationSlackWorkspace();
    $workspace = Workspace::factory()->create(['name' => 'Marketing']);
    $owner = invitationWorkspaceOwner($workspace);

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invitations", [
            'emails' => ['amanda@example.com'],
            'role' => 'member',
            'message' => 'Welcome aboard!',
        ])
        ->assertCreated();

    Queue::assertPushed(SendSlackInvitationJob::class, function (SendSlackInvitationJob $job) {
        $button = end($job->blocks)['elements'][0];

        return $job->email === 'amanda@example.com'
            && $job->blocks[0]['text']['text'] === "*Ernesto Afane* invited you to join the *Marketing* workspace\n>Welcome aboard!"
            && $button['text']['text'] === 'Accept invitation'
            && str_starts_with($button['url'], 'http://frontend.test/invitations/');
    });
});

test('someone without an account is found in slack by email and messaged', function () {
    Queue::fake();
    Http::fake([
        'slack.com/api/users.lookupByEmail*' => Http::response(['ok' => true, 'user' => ['id' => 'UAMANDA', 'real_name' => 'Amanda']]),
    ]);
    invitationSlackWorkspace();
    $workspace = Workspace::factory()->create();
    $owner = invitationWorkspaceOwner($workspace);

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invitations", ['emails' => ['amanda@example.com'], 'role' => 'member'])
        ->assertCreated();

    $messages = runSlackInvitationJobs();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->channel)->toBe('UAMANDA');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'users.lookupByEmail') && $request['email'] === 'amanda@example.com');
});

test('an existing account found by email gets linked so later notifications reach slack', function () {
    Queue::fake();
    Http::fake([
        'slack.com/api/users.lookupByEmail*' => Http::response(['ok' => true, 'user' => ['id' => 'UAMANDA', 'real_name' => 'Amanda']]),
    ]);
    $installation = invitationSlackWorkspace();
    $amanda = User::factory()->create(['email' => 'amanda@example.com']);
    $workspace = Workspace::factory()->create();
    $owner = invitationWorkspaceOwner($workspace);

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invitations", ['emails' => ['Amanda@Example.com'], 'role' => 'member'])
        ->assertCreated();

    runSlackInvitationJobs();

    $this->assertDatabaseHas('slack_user_links', [
        'user_id' => $amanda->id,
        'slack_installation_id' => $installation->id,
        'slack_user_id' => 'UAMANDA',
    ]);
});

test('a member who already linked slack is messaged without asking slack who they are', function () {
    Queue::fake();
    Http::fake();
    $installation = invitationSlackWorkspace();
    $amanda = User::factory()->create(['email' => 'amanda@example.com']);
    SlackUserLink::create(['user_id' => $amanda->id, 'slack_installation_id' => $installation->id, 'slack_user_id' => 'ULINKED']);
    $workspace = Workspace::factory()->create();
    $owner = invitationWorkspaceOwner($workspace);

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invitations", ['emails' => ['amanda@example.com'], 'role' => 'member'])
        ->assertCreated();

    $messages = runSlackInvitationJobs();

    expect($messages[0]->channel)->toBe('ULINKED');
    Http::assertNothingSent();
});

test('nobody in slack with that email means no slack message', function () {
    Queue::fake();
    Http::fake(['slack.com/api/users.lookupByEmail*' => Http::response(['ok' => false, 'error' => 'users_not_found'])]);
    invitationSlackWorkspace();
    $workspace = Workspace::factory()->create();
    $owner = invitationWorkspaceOwner($workspace);

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invitations", ['emails' => ['stranger@example.com'], 'role' => 'member'])
        ->assertCreated();

    expect(runSlackInvitationJobs())->toBeEmpty();
});

test('a workspace connected without users:read.email only reaches linked members', function () {
    Queue::fake();
    Http::fake();
    invitationSlackWorkspace(['scopes' => 'chat:write,im:write']);
    $workspace = Workspace::factory()->create();
    $owner = invitationWorkspaceOwner($workspace);

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invitations", ['emails' => ['amanda@example.com'], 'role' => 'member'])
        ->assertCreated();

    expect(runSlackInvitationJobs())->toBeEmpty();
    Http::assertNothingSent();
});

test('no slack message is queued when invitations are switched off or slack is not connected', function () {
    Queue::fake();
    $workspace = Workspace::factory()->create();
    $owner = invitationWorkspaceOwner($workspace);

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invitations", ['emails' => ['first@example.com'], 'role' => 'member'])
        ->assertCreated();

    invitationSlackWorkspace(['notify_invitations' => false]);

    $this->actingAs($owner, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/invitations", ['emails' => ['second@example.com'], 'role' => 'member'])
        ->assertCreated();

    Queue::assertNotPushed(SendSlackInvitationJob::class);
});

test('a board invitation is also sent on slack', function () {
    Queue::fake();
    invitationSlackWorkspace();
    $workspace = Workspace::factory()->create();
    $owner = invitationWorkspaceOwner($workspace);
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'label' => 'Launch plan']);

    $this->actingAs($owner, 'api')
        ->postJson("/api/boards/{$board->id}/invitations", ['emails' => ['amanda@example.com']])
        ->assertCreated();

    Queue::assertPushed(SendSlackInvitationJob::class, function (SendSlackInvitationJob $job) {
        $button = end($job->blocks)['elements'][0];

        return $job->blocks[0]['text']['text'] === '*Ernesto Afane* invited you to the *Launch plan* board'
            && $button['text']['text'] === 'View invitation'
            && str_starts_with($button['url'], 'http://frontend.test/board-invitations/');
    });
});

test('members added to a board from the share dialog are told on slack', function () {
    Queue::fake();
    invitationSlackWorkspace();
    $workspace = Workspace::factory()->create();
    $owner = invitationWorkspaceOwner($workspace);
    $member = User::factory()->create(['email' => 'amanda@example.com']);
    $workspace->users()->attach($member->id, ['role' => 'member']);
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'label' => 'Launch plan',
        'board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
    ]);

    $this->actingAs($owner, 'api')
        ->postJson("/api/boards/{$board->id}/collaborators", ['user_ids' => [$member->id]])
        ->assertCreated();

    Queue::assertPushed(SendSlackInvitationJob::class, function (SendSlackInvitationJob $job) use ($board) {
        return $job->email === 'amanda@example.com'
            && $job->blocks[0]['text']['text'] === '*Ernesto Afane* gave you access to the *Launch plan* board'
            && end($job->blocks)['elements'][0]['url'] === "http://frontend.test/boards/{$board->id}";
    });
});

test('an administrator can switch slack invitations off for a workspace', function () {
    $installation = invitationSlackWorkspace();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->patchJson("/api/integrations/slack/workspaces/{$installation->id}", ['notify_invitations' => false])
        ->assertOk()
        ->assertJsonPath('workspaces.0.notify_invitations', false)
        ->assertJsonPath('workspaces.0.can_lookup_by_email', true);

    expect($installation->fresh()->notify_invitations)->toBeFalse();
});

test('a member cannot change the slack invitation setting', function () {
    $installation = invitationSlackWorkspace();

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/integrations/slack/workspaces/{$installation->id}", ['notify_invitations' => false])
        ->assertForbidden();

    expect($installation->fresh()->notify_invitations)->toBeTrue();
});
