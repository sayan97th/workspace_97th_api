<?php

namespace App\Services\Slack;

use App\Jobs\SendSlackMessageJob;
use App\Models\Notification;
use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use App\Services\Notification\NotificationService;

/**
 * Turns notifications and automation messages into Slack messages and queues them.
 * {@see NotificationService} calls {@see self::deliverNotification()}
 * for every in-app notification, so a mention, a reply, an assignment and the rest reach
 * Slack without each trigger knowing Slack exists.
 */
class SlackNotifier
{
    public function __construct(private readonly SlackService $slack_service) {}

    /**
     * Sends `$notification` to `$recipient` as a Slack direct message, when Slack is
     * connected, the recipient linked their account, and their own `<type>_slack`
     * preference has not been switched off (on by default, like the app and email ones).
     */
    public function deliverNotification(Notification $notification, User $recipient): void
    {
        if ($notification->type === Notification::TYPE_TEST) {
            return;
        }

        $preferences = $recipient->notification_preferences ?? [];
        if (($preferences["{$notification->type}_slack"] ?? true) === false) {
            return;
        }

        $actor_name = $notification->actor?->full_name ?: 'Someone';
        $message = sprintf(
            '*%s* %s %s',
            $this->escape($actor_name),
            $this->escape(lcfirst($notification->action_label)),
            $this->escape($notification->action_target),
        );

        $this->sendToUser(
            user: $recipient,
            mrkdwn: $message,
            fallback_text: "{$actor_name} ".lcfirst($notification->action_label)." {$notification->action_target}",
            link: $notification->link,
            context: $notification->board?->label,
        );
    }

    /**
     * Sends a plain text message (never interpreted as Slack markup) to one member.
     * Returns false when nothing was queued: Slack is not connected or the member has
     * not linked their Slack account.
     */
    public function notifyUser(User $user, string $message, ?string $link = null, ?string $context = null): bool
    {
        return $this->sendToUser($user, $this->escape($message), $message, $link, $context);
    }

    /**
     * Sends a plain text message to a channel. Returns false when Slack is not connected.
     */
    public function notifyChannel(string $channel_id, string $message, ?string $link = null, ?string $context = null): bool
    {
        $installation = $this->slack_service->installation();
        if (! $installation) {
            return false;
        }

        $this->dispatch($installation, $channel_id, $this->escape($message), $message, $link, $context);

        return true;
    }

    /**
     * Sends a message straight away instead of through the queue, so a "send me a test
     * message" button can show the real Slack error to the person who clicked it.
     *
     * @throws SlackException
     */
    public function sendTestToUser(User $user, SlackClient $client): void
    {
        $link = $user->slackLink;

        if (! $link) {
            throw new SlackException('not_linked', 'Connect your Slack account first.');
        }

        $installation = $link->installation;

        $text = 'Slack is connected. You will receive your workspace notifications here.';

        $client->postMessage($installation->bot_token, $link->slack_user_id, $text, $this->buildBlocks($this->escape($text), null, null));
    }

    /**
     * Sends a custom test notification from `$actor` to another member right away, not through
     * the queue, so the diagnostics page can show the real Slack error. Uses the same direct
     * message channel and block layout as every real notification.
     *
     * @throws SlackException
     */
    public function sendTestNotification(SlackUserLink $recipient_link, User $actor, string $message, SlackClient $client): void
    {
        $sender_name = $actor->full_name ?: 'An administrator';
        $mrkdwn = sprintf("*%s* sent you a test notification:\n>%s", $this->escape($sender_name), str_replace("\n", "\n>", $this->escape($message)));

        $client->postMessage(
            $recipient_link->installation->bot_token,
            $recipient_link->slack_user_id,
            mb_substr("{$sender_name} sent you a test notification: {$message}", 0, 3000),
            $this->buildBlocks($mrkdwn, 'Sent from the Slack diagnostics page', null),
        );
    }

    /**
     * Slack treats `&`, `<` and `>` as markup, so user supplied text is escaped to keep
     * it from forming links or `<!channel>` style broadcasts.
     */
    public function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    private function sendToUser(User $user, string $mrkdwn, string $fallback_text, ?string $link, ?string $context): bool
    {
        $slack_link = $user->slackLink()->with('installation')->first();

        if (! $slack_link) {
            return false;
        }

        $this->dispatch($slack_link->installation, $slack_link->slack_user_id, $mrkdwn, $fallback_text, $link, $context);

        return true;
    }

    private function dispatch(SlackInstallation $installation, string $channel, string $mrkdwn, string $fallback_text, ?string $link, ?string $context): void
    {
        SendSlackMessageJob::dispatch(
            $installation->id,
            $channel,
            mb_substr($fallback_text, 0, 3000),
            $this->buildBlocks($mrkdwn, $context, $link),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBlocks(string $mrkdwn, ?string $context, ?string $link): array
    {
        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_substr($mrkdwn, 0, 3000)]],
        ];

        if ($context) {
            $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $this->escape($context)]]];
        }

        if ($link) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [[
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Open in workspace'],
                    'url' => rtrim((string) config('app.frontend_url'), '/').$link,
                ]],
            ];
        }

        return $blocks;
    }
}
