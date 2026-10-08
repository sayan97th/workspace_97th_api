<?php

namespace App\Services\Slack;

use App\Enums\NotificationPreferenceKey;
use App\Enums\SlackNotificationTest;
use App\Models\Notification;
use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the Slack notification test suite of /admin/test/slack/notifications. Every test talks
 * to the real Slack workspace right away, never through the queue (except the one that tests the
 * queue), so its result carries Slack's real answer, each Slack call it made and a link to the
 * message it produced.
 *
 * A test is `skipped` when it cannot run at all (no workspace, missing scope, recipient not linked),
 * `failed` when Slack rejected a call and `warning` when the message went out but something a real
 * notification depends on is off, such as the recipient's own preferences.
 */
class SlackNotificationTestRunner
{
    /** Where the last `app_mention` event Slack sent is remembered, see {@see self::recordAppMention()}. */
    private const APP_MENTION_CACHE_KEY = 'slack_notification_tests:last_app_mention';

    /** In-app path the "Open in workspace" button of the sample notifications points to. */
    private const SAMPLE_LINK = '/my-work';

    private const SAMPLE_BOARD_LABEL = 'Slack notification tests';

    private const SAMPLE_ITEM_NAME = 'Slack test item';

    private const TEST_REACTION = 'white_check_mark';

    private const SCHEDULE_DELAY_SECONDS = 60;

    public function __construct(
        private readonly SlackClient $client,
        private readonly SlackNotifier $slack_notifier,
        private readonly SlackAppCredentials $credentials,
    ) {}

    /**
     * Every test with the scopes the active workspace is missing for it, plus what the page
     * needs to explain them: the workspace, the granted scopes and the queue connection.
     *
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        $installation = SlackInstallation::current();

        return [
            'workspace' => $installation ? ['team_id' => $installation->team_id, 'team_name' => $installation->team_name] : null,
            'granted_scopes' => $installation ? $this->grantedScopes($installation) : [],
            'queue_connection' => (string) config('queue.default'),
            'can_receive_events' => $this->credentials->canReceiveEvents(),
            'tests' => array_map(fn (SlackNotificationTest $test) => [
                ...$test->describe(),
                'missing_scopes' => $installation ? $this->missingScopes($test, $installation) : [],
            ], SlackNotificationTest::cases()),
        ];
    }

    /**
     * Runs one test. `$recipient`, `$channel_id`, `$slack_user_id` (any Slack member) and `$message`
     * are only read by the tests that need them, the request already made sure they are present.
     *
     * @return array<string, mixed>
     */
    public function run(SlackNotificationTest $test, User $actor, ?User $recipient = null, ?string $channel_id = null, ?string $slack_user_id = null, ?string $message = null): array
    {
        $run = new SlackNotificationTestRun($test);
        $installation = SlackInstallation::current();

        if (! $installation) {
            return $run->finish(SlackNotificationTestRun::STATUS_SKIPPED, 'Connect a Slack workspace first.')->toArray();
        }

        $missing_scopes = $this->missingScopes($test, $installation);
        if ($missing_scopes !== []) {
            return $run->finish(SlackNotificationTestRun::STATUS_SKIPPED, 'The workspace was installed without '.implode(', ', $missing_scopes).'. Use "Reconnect" on the workspace in Administration > Integrations to grant it.')->toArray();
        }

        $recipient_link = null;
        if ($test->needsRecipient()) {
            $recipient_link = $recipient?->slackLinks()->where('slack_installation_id', $installation->id)->with('installation')->first();

            if (! $recipient || ! $recipient_link) {
                return $run->finish(SlackNotificationTestRun::STATUS_SKIPPED, "The recipient has not linked their Slack account in {$installation->team_name} yet.")->toArray();
            }
        }

        try {
            $this->execute($run, $test, $installation, $actor, $recipient, $recipient_link, (string) $channel_id, (string) $slack_user_id, (string) $message);
        } catch (SlackException $exception) {
            $run->finish(SlackNotificationTestRun::STATUS_FAILED, SlackErrorMessage::describe($exception));
        }

        return $run->toArray();
    }

    /**
     * Remembers the last time someone mentioned the app, which is the only proof the
     * `app_mention` event subscription works. Called by the events endpoint.
     *
     * @param  array<string, mixed>  $event
     */
    public function recordAppMention(string $team_id, array $event): void
    {
        Cache::forever(self::APP_MENTION_CACHE_KEY, [
            'team_id' => $team_id,
            'slack_user_id' => is_string($event['user'] ?? null) ? $event['user'] : null,
            'channel_id' => is_string($event['channel'] ?? null) ? $event['channel'] : null,
            'received_at' => now()->toIso8601String(),
        ]);
    }

    private function execute(SlackNotificationTestRun $run, SlackNotificationTest $test, SlackInstallation $installation, User $actor, ?User $recipient, ?SlackUserLink $recipient_link, string $channel_id, string $slack_user_id, string $message): void
    {
        $token = $installation->bot_token;

        match ($test) {
            SlackNotificationTest::BotIdentity => $this->runBotIdentity($run, $installation),
            SlackNotificationTest::RecipientLookup => $this->runRecipientLookup($run, $token, $recipient, $recipient_link),
            SlackNotificationTest::RecipientSettings => $this->runRecipientSettings($run, $recipient),
            SlackNotificationTest::MentionNotification,
            SlackNotificationTest::AssignmentNotification,
            SlackNotificationTest::ReplyNotification,
            SlackNotificationTest::ReactionNotification,
            SlackNotificationTest::DueDateReminder,
            SlackNotificationTest::AutomationNotification => $this->runSampleNotification($run, $test, $token, $actor, $recipient, $recipient_link),
            SlackNotificationTest::QueuedNotification => $this->runQueuedNotification($run, $actor, $recipient),
            SlackNotificationTest::DirectMessage => $this->runDirectMessage($run, $token, $actor, $recipient, $recipient_link),
            SlackNotificationTest::RichMessage => $this->runRichMessage($run, $token, $installation, $actor, $recipient_link),
            SlackNotificationTest::SlackMemberMessage => $this->runSlackMemberMessage($run, $installation, $actor, $slack_user_id, $message),
            SlackNotificationTest::ChannelMessage => $this->runChannelMessage($run, $token, $actor, $channel_id),
            SlackNotificationTest::ChannelMention => $this->runChannelMention($run, $token, $actor, $recipient, $recipient_link, $channel_id),
            SlackNotificationTest::EphemeralMessage => $this->runEphemeralMessage($run, $token, $actor, $recipient, $recipient_link, $channel_id),
            SlackNotificationTest::ThreadReply => $this->runThreadReply($run, $token, $actor, $channel_id),
            SlackNotificationTest::MessageUpdate => $this->runMessageUpdate($run, $token, $actor, $channel_id),
            SlackNotificationTest::Reaction => $this->runReaction($run, $token, $actor, $channel_id),
            SlackNotificationTest::FileUpload => $this->runFileUpload($run, $token, $actor, $channel_id),
            SlackNotificationTest::ScheduledMessage => $this->runScheduledMessage($run, $token, $actor, $channel_id),
            SlackNotificationTest::AppMentionEvent => $this->runAppMentionEvent($run, $installation),
        };
    }

    private function runBotIdentity(SlackNotificationTestRun $run, SlackInstallation $installation): void
    {
        $identity = $this->call($run, 'auth.test', fn () => $this->client->authTest($installation->bot_token));

        if (($identity['team_id'] ?? null) !== $installation->team_id) {
            $run->finish(SlackNotificationTestRun::STATUS_FAILED, 'The bot token belongs to a different workspace than the one stored. Disconnect and add it to Slack again.');

            return;
        }

        $run->finish(SlackNotificationTestRun::STATUS_PASSED, sprintf('Authenticated as %s in %s.', $identity['user'] ?? 'the bot', $identity['team'] ?? $installation->team_name));
    }

    private function runRecipientLookup(SlackNotificationTestRun $run, string $token, User $recipient, SlackUserLink $recipient_link): void
    {
        $profile = $this->call($run, 'users.info', fn () => $this->client->userInfo($token, $recipient_link->slack_user_id))['user'] ?? [];
        $slack_name = $profile['real_name'] ?? $profile['name'] ?? $recipient_link->slack_user_id;

        if (($profile['deleted'] ?? false) === true) {
            $run->finish(SlackNotificationTestRun::STATUS_FAILED, "{$slack_name} was deactivated in Slack, direct messages cannot reach them.");

            return;
        }

        try {
            $match = $this->client->lookupUserByEmail($token, $recipient->email)['user'] ?? [];
        } catch (SlackException $exception) {
            if ($exception->error_code !== 'users_not_found') {
                $run->addStep('users.lookupByEmail', SlackNotificationTestRun::STATUS_FAILED, $exception->error_code);

                throw $exception;
            }

            $run->addStep('users.lookupByEmail', SlackNotificationTestRun::STATUS_WARNING, 'users_not_found');
            $run->finish(SlackNotificationTestRun::STATUS_WARNING, "{$slack_name} is active in Slack, but no Slack member uses {$recipient->email}. Notifications still arrive through the linked account, only matching members by email will skip them.");

            return;
        }

        $run->addStep('users.lookupByEmail', SlackNotificationTestRun::STATUS_PASSED);

        if (($match['id'] ?? null) !== $recipient_link->slack_user_id) {
            $run->finish(SlackNotificationTestRun::STATUS_WARNING, "{$recipient->email} belongs to a different Slack member than the linked account. Notifications go to the linked account ({$slack_name}).");

            return;
        }

        $time_zone = is_string($profile['tz'] ?? null) ? " Time zone {$profile['tz']}." : '';
        $run->finish(SlackNotificationTestRun::STATUS_PASSED, "{$slack_name} is active in Slack and uses {$recipient->email}.{$time_zone}");
    }

    private function runRecipientSettings(SlackNotificationTestRun $run, User $recipient): void
    {
        $preferences = $recipient->notification_preferences ?? [];

        // The in-app switch gates every channel, so a type turned off there never reaches Slack either.
        $silenced_types = array_values(array_filter(
            NotificationPreferenceKey::values(),
            fn (string $key) => ($preferences["{$key}_slack"] ?? true) === false || ($preferences["{$key}_app"] ?? true) === false,
        ));

        $run->addStep('notification_preferences', $silenced_types === [] ? SlackNotificationTestRun::STATUS_PASSED : SlackNotificationTestRun::STATUS_WARNING, $silenced_types === []
            ? 'Every notification type can reach Slack.'
            : 'Off for '.implode(', ', array_map($this->preferenceLabel(...), $silenced_types)).'.');

        $is_quiet = $recipient->isInQuietHours();
        $run->addStep('quiet_hours', $is_quiet ? SlackNotificationTestRun::STATUS_WARNING : SlackNotificationTestRun::STATUS_PASSED, $recipient->quiet_hours_enabled
            ? "Quiet hours from {$recipient->quiet_hours_start} to {$recipient->quiet_hours_end}."
            : 'Quiet hours are off.');

        $problems = [];
        if ($silenced_types !== []) {
            $problems[] = "{$recipient->full_name} turned off Slack for ".count($silenced_types).' notification '.str('type')->plural(count($silenced_types));
        }
        if ($is_quiet) {
            $problems[] = 'quiet hours are active right now, so Slack messages wait until they end';
        }

        $run->finish(
            $problems === [] ? SlackNotificationTestRun::STATUS_PASSED : SlackNotificationTestRun::STATUS_WARNING,
            $problems === [] ? "Nothing in {$recipient->full_name}'s settings stops Slack notifications." : ucfirst(implode(', and ', $problems)).'.',
        );
    }

    private function runSampleNotification(SlackNotificationTestRun $run, SlackNotificationTest $test, string $token, User $actor, User $recipient, SlackUserLink $recipient_link): void
    {
        $type = (string) $test->notificationType();
        $notification = $this->sampleNotification($type, $actor);

        $response = $this->call($run, 'chat.postMessage', fn () => $this->slack_notifier->sendNotificationNow($notification, $recipient_link, $this->client));
        $this->addPermalink($run, $token, $response);

        $preferences = $recipient->notification_preferences ?? [];
        $label = $this->preferenceLabel($type);

        if (($preferences["{$type}_slack"] ?? true) === false || ($preferences["{$type}_app"] ?? true) === false) {
            $run->finish(SlackNotificationTestRun::STATUS_WARNING, "Delivered, but {$recipient->full_name} turned off \"{$label}\" notifications, so real ones would not reach Slack.");

            return;
        }

        $run->finish(SlackNotificationTestRun::STATUS_PASSED, "Delivered a sample \"{$label}\" notification to {$recipient->full_name}.");
    }

    private function runQueuedNotification(SlackNotificationTestRun $run, User $actor, User $recipient): void
    {
        $was_queued = $this->slack_notifier->notifyUser(
            $recipient,
            "Slack notification test sent by {$actor->full_name} through the background queue.",
            self::SAMPLE_LINK,
            self::SAMPLE_BOARD_LABEL,
        );

        if (! $was_queued) {
            $run->addStep('SendSlackMessageJob', SlackNotificationTestRun::STATUS_FAILED);
            $run->finish(SlackNotificationTestRun::STATUS_FAILED, 'Nothing was queued, the recipient has no Slack account linked in the active workspace.');

            return;
        }

        $connection = (string) config('queue.default');
        $run->addStep('SendSlackMessageJob', SlackNotificationTestRun::STATUS_PASSED, "Dispatched on the {$connection} connection.");

        $run->finish(SlackNotificationTestRun::STATUS_PASSED, $connection === 'sync'
            ? 'Sent right away because the queue connection is sync. If it did not arrive, check the Laravel log for "Slack message not delivered".'
            : "Queued on the {$connection} connection. It arrives once a queue worker picks it up, if it never does, start one with php artisan queue:work.");
    }

    private function runDirectMessage(SlackNotificationTestRun $run, string $token, User $actor, User $recipient, SlackUserLink $recipient_link): void
    {
        $text = "Slack notification test: a plain direct message sent by {$actor->full_name}.";

        $response = $this->call($run, 'chat.postMessage', fn () => $this->client->postMessage($token, $recipient_link->slack_user_id, $text));
        $this->addPermalink($run, $token, $response);

        $run->finish(SlackNotificationTestRun::STATUS_PASSED, "Delivered to {$recipient->full_name}.");
    }

    private function runRichMessage(SlackNotificationTestRun $run, string $token, SlackInstallation $installation, User $actor, SlackUserLink $recipient_link): void
    {
        $sent_at = now()->toDayDateTimeString();
        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => 'Slack notification test']],
            ['type' => 'section', 'fields' => [
                ['type' => 'mrkdwn', 'text' => "*Sent by*\n".$this->slack_notifier->escape($actor->full_name)],
                ['type' => 'mrkdwn', 'text' => "*Workspace*\n".$this->slack_notifier->escape($installation->team_name)],
                ['type' => 'mrkdwn', 'text' => "*Sent at*\n{$sent_at}"],
                ['type' => 'mrkdwn', 'text' => "*Layout*\nHeader, fields, divider, context and button"],
            ]],
            ['type' => 'divider'],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'If this message shows *bold text*, four fields and a button, Slack renders rich notifications correctly.']],
            ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => 'Sent from the Slack notification test suite']]],
            ['type' => 'actions', 'elements' => [[
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Open the test suite'],
                'url' => rtrim((string) config('app.frontend_url'), '/').'/admin/test/slack/notifications',
            ]]],
        ];

        $response = $this->call($run, 'chat.postMessage', fn () => $this->client->postMessage($token, $recipient_link->slack_user_id, 'Slack notification test with a rich layout.', $blocks));
        $this->addPermalink($run, $token, $response);

        $run->finish(SlackNotificationTestRun::STATUS_PASSED, 'Delivered a Block Kit message. Open it to confirm every block renders.');
    }

    /**
     * Sends `$message` to any person in the Slack workspace, linked to the app or not. Their
     * profile is read first so a deactivated member or a bot fails with a clear reason, then the
     * direct message conversation is opened when `im:write` was granted. Without it the member id
     * is used as the channel, which Slack also turns into the app's direct message.
     */
    private function runSlackMemberMessage(SlackNotificationTestRun $run, SlackInstallation $installation, User $actor, string $slack_user_id, string $message): void
    {
        $token = $installation->bot_token;
        $profile = $this->call($run, 'users.info', fn () => $this->client->userInfo($token, $slack_user_id))['user'] ?? [];
        $slack_name = $profile['real_name'] ?? $profile['name'] ?? $slack_user_id;

        if (($profile['deleted'] ?? false) === true) {
            $run->finish(SlackNotificationTestRun::STATUS_FAILED, "{$slack_name} was deactivated in Slack, a direct message cannot reach them.");

            return;
        }

        if (($profile['is_bot'] ?? false) === true) {
            $run->finish(SlackNotificationTestRun::STATUS_FAILED, "{$slack_name} is a bot, choose a person.");

            return;
        }

        $channel = $slack_user_id;
        if ($installation->hasScope('im:write')) {
            $conversation = $this->call($run, 'conversations.open', fn () => $this->client->openConversation($token, $slack_user_id));
            $channel = (string) ($conversation['channel']['id'] ?? $slack_user_id);
        } else {
            $run->addStep('conversations.open', SlackNotificationTestRun::STATUS_WARNING, 'im:write not granted, sent to the member id instead.');
        }

        $sender_name = $actor->full_name ?: 'An administrator';
        $mrkdwn = sprintf("*%s* sent you a message:\n>%s", $this->slack_notifier->escape($sender_name), str_replace("\n", "\n>", $this->slack_notifier->escape($message)));

        $response = $this->call($run, 'chat.postMessage', fn () => $this->client->postMessage(
            $token,
            $channel,
            mb_substr("{$sender_name} sent you a message: {$message}", 0, 3000),
            $this->slack_notifier->buildBlocks($mrkdwn, 'Sent from the Slack notification test suite', null),
        ));
        $this->addPermalink($run, $token, $response);

        $run->finish(SlackNotificationTestRun::STATUS_PASSED, "Delivered to {$slack_name} in {$installation->team_name}.");
    }

    private function runChannelMessage(SlackNotificationTestRun $run, string $token, User $actor, string $channel_id): void
    {
        $response = $this->call($run, 'chat.postMessage', fn () => $this->client->postMessage($token, $channel_id, "Slack notification test: a channel message posted by {$actor->full_name}."));
        $ts = (string) ($response['ts'] ?? '');
        $this->addPermalink($run, $token, $response);

        try {
            $messages = $this->client->conversationHistory($token, $channel_id, $ts, $ts)['messages'] ?? [];
        } catch (SlackException $exception) {
            if ($exception->error_code !== 'missing_scope') {
                $run->addStep('conversations.history', SlackNotificationTestRun::STATUS_FAILED, $exception->error_code);

                throw $exception;
            }

            $run->addStep('conversations.history', SlackNotificationTestRun::STATUS_WARNING, 'missing_scope');
            $run->finish(SlackNotificationTestRun::STATUS_WARNING, 'Posted, but the app cannot read this channel back. Grant channels:history for public channels or groups:history for private ones.');

            return;
        }

        $is_found = collect($messages)->contains(fn ($message) => is_array($message) && ($message['ts'] ?? null) === $ts);
        $run->addStep('conversations.history', $is_found ? SlackNotificationTestRun::STATUS_PASSED : SlackNotificationTestRun::STATUS_WARNING);

        $run->finish(
            $is_found ? SlackNotificationTestRun::STATUS_PASSED : SlackNotificationTestRun::STATUS_WARNING,
            $is_found ? 'Posted and confirmed in the channel history.' : 'Posted, but the message did not show up in the channel history yet.',
        );
    }

    private function runChannelMention(SlackNotificationTestRun $run, string $token, User $actor, User $recipient, SlackUserLink $recipient_link, string $channel_id): void
    {
        // The mention itself is Slack markup on purpose, everything around it is escaped.
        $text = "<@{$recipient_link->slack_user_id}> ".$this->slack_notifier->escape("Slack notification test: {$actor->full_name} mentioned you in this channel.");

        $response = $this->call($run, 'chat.postMessage', fn () => $this->client->postMessage($token, $channel_id, $text));
        $this->addPermalink($run, $token, $response);

        $run->finish(SlackNotificationTestRun::STATUS_PASSED, "Posted, {$recipient->full_name} gets a Slack mention notification if they are in the channel.");
    }

    private function runEphemeralMessage(SlackNotificationTestRun $run, string $token, User $actor, User $recipient, SlackUserLink $recipient_link, string $channel_id): void
    {
        $this->call($run, 'chat.postEphemeral', fn () => $this->client->postEphemeral(
            $token,
            $channel_id,
            $recipient_link->slack_user_id,
            "Slack notification test: only you can see this message, sent by {$actor->full_name}.",
        ));

        $run->finish(SlackNotificationTestRun::STATUS_PASSED, "Posted, only {$recipient->full_name} sees it, and only until they reload Slack.");
    }

    private function runThreadReply(SlackNotificationTestRun $run, string $token, User $actor, string $channel_id): void
    {
        $parent = $this->call($run, 'chat.postMessage', fn () => $this->client->postMessage($token, $channel_id, "Slack notification test: a thread started by {$actor->full_name}."));
        $parent_ts = (string) ($parent['ts'] ?? '');

        $reply = $this->call($run, 'chat.postMessage (thread)', fn () => $this->client->postMessage(
            $token,
            $channel_id,
            'This reply belongs to the thread above.',
            options: ['thread_ts' => $parent_ts],
        ));
        $this->addPermalink($run, $token, $reply, 'Open the reply in Slack');

        $run->finish(SlackNotificationTestRun::STATUS_PASSED, 'Posted a message and answered it in its thread.');
    }

    private function runMessageUpdate(SlackNotificationTestRun $run, string $token, User $actor, string $channel_id): void
    {
        $response = $this->call($run, 'chat.postMessage', fn () => $this->client->postMessage($token, $channel_id, ":hourglass_flowing_sand: Slack notification test by {$actor->full_name}: in progress."));
        $ts = (string) ($response['ts'] ?? '');

        $this->call($run, 'chat.update', fn () => $this->client->updateMessage($token, $channel_id, $ts, ":white_check_mark: Slack notification test by {$actor->full_name}: completed. This message was edited by the app."));
        $this->addPermalink($run, $token, $response);

        $run->finish(SlackNotificationTestRun::STATUS_PASSED, 'Posted a message and edited it, it should now read "completed".');
    }

    private function runReaction(SlackNotificationTestRun $run, string $token, User $actor, string $channel_id): void
    {
        $response = $this->call($run, 'chat.postMessage', fn () => $this->client->postMessage($token, $channel_id, "Slack notification test: the app reacts to this message, posted by {$actor->full_name}."));
        $ts = (string) ($response['ts'] ?? '');
        $this->addPermalink($run, $token, $response);

        $this->call($run, 'reactions.add', fn () => $this->client->addReaction($token, $channel_id, $ts, self::TEST_REACTION));
        $reactions = $this->call($run, 'reactions.get', fn () => $this->client->getReactions($token, $channel_id, $ts))['message']['reactions'] ?? [];

        $is_saved = collect($reactions)->contains(fn ($reaction) => is_array($reaction) && ($reaction['name'] ?? null) === self::TEST_REACTION);

        $run->finish(
            $is_saved ? SlackNotificationTestRun::STATUS_PASSED : SlackNotificationTestRun::STATUS_WARNING,
            $is_saved ? 'Added :'.self::TEST_REACTION.': and read it back from Slack.' : 'Slack accepted the reaction, but it was not on the message when read back.',
        );
    }

    private function runFileUpload(SlackNotificationTestRun $run, string $token, User $actor, string $channel_id): void
    {
        $contents = implode("\n", [
            'Slack notification test file',
            "Uploaded by: {$actor->full_name}",
            'Uploaded at: '.now()->toIso8601String(),
            'If you can open this file in Slack, file attachments work.',
        ])."\n";

        $file = $this->call($run, 'files.completeUploadExternal', fn () => $this->client->uploadFile(
            $token,
            $channel_id,
            'slack-notification-test.txt',
            $contents,
            'Slack notification test file',
            'Slack notification test: a file attachment.',
        ));

        $info = $this->call($run, 'files.info', fn () => $this->client->fileInfo($token, (string) ($file['id'] ?? '')))['file'] ?? [];

        if (is_string($info['permalink'] ?? null)) {
            $run->addLink('Open the file in Slack', $info['permalink']);
        }

        $run->finish(SlackNotificationTestRun::STATUS_PASSED, sprintf('Uploaded %s (%d bytes) to the channel.', $info['name'] ?? 'the file', $info['size'] ?? strlen($contents)));
    }

    private function runScheduledMessage(SlackNotificationTestRun $run, string $token, User $actor, string $channel_id): void
    {
        $post_at = now()->addSeconds(self::SCHEDULE_DELAY_SECONDS)->getTimestamp();

        $response = $this->call($run, 'chat.scheduleMessage', fn () => $this->client->scheduleMessage(
            $token,
            $channel_id,
            $post_at,
            "Slack notification test: a scheduled message set up by {$actor->full_name} a minute ago.",
        ));

        $delivery_time = Carbon::createFromTimestamp((int) ($response['post_at'] ?? $post_at))->toTimeString();

        $run->finish(SlackNotificationTestRun::STATUS_PASSED, "Scheduled for {$delivery_time} (server time), it appears in the channel in about a minute.");
    }

    private function runAppMentionEvent(SlackNotificationTestRun $run, SlackInstallation $installation): void
    {
        $last_mention = Cache::get(self::APP_MENTION_CACHE_KEY);
        $can_receive_events = $this->credentials->canReceiveEvents();

        $run->addStep('events_url', $can_receive_events ? SlackNotificationTestRun::STATUS_PASSED : SlackNotificationTestRun::STATUS_WARNING, $this->credentials->eventsUrl());

        if (is_array($last_mention) && ($last_mention['team_id'] ?? null) === $installation->team_id) {
            $received_at = Carbon::parse($last_mention['received_at'])->toDayDateTimeString();
            $run->addStep('app_mention', SlackNotificationTestRun::STATUS_PASSED, $received_at);
            $who = $last_mention['slack_user_id'] ? "Slack member {$last_mention['slack_user_id']}" : 'an unknown Slack member';
            $run->finish(SlackNotificationTestRun::STATUS_PASSED, "The last mention of the app arrived on {$received_at}, from {$who}.");

            return;
        }

        $run->addStep('app_mention', SlackNotificationTestRun::STATUS_WARNING, 'No event received yet.');
        $run->finish(SlackNotificationTestRun::STATUS_WARNING, $can_receive_events
            ? 'No mention received yet. Subscribe the app to the app_mention bot event, invite it to a channel, mention it there, then run this test again.'
            : 'Slack cannot reach the events URL because it is not public HTTPS. Expose the API through an HTTPS tunnel, subscribe to the app_mention bot event, then mention the app in a channel.');
    }

    /**
     * A notification that is never saved, shaped like the real one of `$type`, so the
     * sample tests reuse the exact text and blocks of {@see SlackNotifier::deliverNotification()}.
     */
    private function sampleNotification(string $type, User $actor): Notification
    {
        [$action_label, $action_target] = match ($type) {
            Notification::TYPE_MENTIONED => ['Mentioned you', sprintf('in an update on "%s"', self::SAMPLE_ITEM_NAME)],
            Notification::TYPE_ASSIGNED => ['Assigned you', sprintf('to "%s" on the Board "%s"', self::SAMPLE_ITEM_NAME, self::SAMPLE_BOARD_LABEL)],
            Notification::TYPE_REPLIED_UPDATE => ['Replied to your update', sprintf('on "%s"', self::SAMPLE_ITEM_NAME)],
            Notification::TYPE_REACTIONS => ['Reacted to your update', sprintf('on "%s"', self::SAMPLE_ITEM_NAME)],
            Notification::TYPE_DUE_DATE_REMINDER => ['Due date reminder', sprintf('"%s" is due on "%s"', self::SAMPLE_ITEM_NAME, now()->toFormattedDateString())],
            default => ['Automation "Notify when status changes"', sprintf('notified you about "%s"', self::SAMPLE_ITEM_NAME)],
        };

        $notification = new Notification([
            'type' => $type,
            'action_label' => $action_label,
            'action_target' => $action_target,
            'link' => self::SAMPLE_LINK,
        ]);

        // Due date reminders are system notifications, they never have an author.
        $notification->setRelation('actor', $type === Notification::TYPE_DUE_DATE_REMINDER ? null : $actor);
        $notification->setRelation('board', new WorkspaceNavigationItem(['label' => self::SAMPLE_BOARD_LABEL]));

        return $notification;
    }

    /**
     * Runs one Slack call and records it as a step, passed or failed with Slack's error code.
     *
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     *
     * @throws SlackException
     */
    private function call(SlackNotificationTestRun $run, string $name, callable $callback): array
    {
        try {
            $response = $callback();
        } catch (SlackException $exception) {
            $run->addStep($name, SlackNotificationTestRun::STATUS_FAILED, $exception->error_code);

            throw $exception;
        }

        $run->addStep($name, SlackNotificationTestRun::STATUS_PASSED);

        return $response;
    }

    /**
     * Adds a link to the message Slack just stored. Best effort, a failure only shows as a step.
     *
     * @param  array<string, mixed>  $message_response
     */
    private function addPermalink(SlackNotificationTestRun $run, string $token, array $message_response, string $label = 'Open in Slack'): void
    {
        $channel = $message_response['channel'] ?? null;
        $ts = $message_response['ts'] ?? null;

        if (! is_string($channel) || ! is_string($ts)) {
            return;
        }

        try {
            $permalink = $this->client->getPermalink($token, $channel, $ts)['permalink'] ?? null;
        } catch (SlackException $exception) {
            $run->addStep('chat.getPermalink', SlackNotificationTestRun::STATUS_WARNING, $exception->error_code);

            return;
        }

        if (is_string($permalink)) {
            $run->addLink($label, $permalink);
        }
    }

    /**
     * @return array<int, string>
     */
    private function grantedScopes(SlackInstallation $installation): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $installation->scopes))));
    }

    /**
     * @return array<int, string>
     */
    private function missingScopes(SlackNotificationTest $test, SlackInstallation $installation): array
    {
        return array_values(array_filter($test->requiredScopes(), fn (string $scope) => ! $installation->hasScope($scope)));
    }

    private function preferenceLabel(string $key): string
    {
        return str_replace('_', ' ', $key);
    }
}
