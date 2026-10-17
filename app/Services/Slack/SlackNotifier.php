<?php

namespace App\Services\Slack;

use App\Jobs\SendSlackInvitationJob;
use App\Jobs\SendSlackMessageJob;
use App\Models\BoardInvitation;
use App\Models\Notification;
use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceNavigationItem;
use App\Services\Notification\NotificationService;
use DateTimeInterface;

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

        $content = $this->notificationContent($notification);

        $this->sendToUser(
            user: $recipient,
            mrkdwn: $content['mrkdwn'],
            fallback_text: $content['fallback_text'],
            link: $notification->link,
            context: $content['context'],
        );
    }

    /**
     * Sends `$notification` to `$recipient_link` right away with the exact text and blocks
     * {@see self::deliverNotification()} queues, skipping the preference gate, so the
     * notification test suite shows what a real notification looks like and Slack's real error.
     * `$notification` does not need to be saved. Returns Slack's chat.postMessage answer.
     *
     * @return array<string, mixed>
     *
     * @throws SlackException
     */
    public function sendNotificationNow(Notification $notification, SlackUserLink $recipient_link, SlackClient $client): array
    {
        $content = $this->notificationContent($notification);

        return $client->postMessage(
            $recipient_link->installation->bot_token,
            $recipient_link->slack_user_id,
            mb_substr($content['fallback_text'], 0, 3000),
            $this->buildBlocks($content['mrkdwn'], $content['context'], $notification->link),
        );
    }

    /**
     * The direct message for one notification: who did what to what, which board it was on.
     * A notification without an actor (a due date reminder) is attributed to "Someone", as it always was.
     *
     * @return array{mrkdwn: string, fallback_text: string, context: string|null}
     */
    private function notificationContent(Notification $notification): array
    {
        $actor_name = $notification->actor?->full_name ?: 'Someone';

        return [
            'mrkdwn' => sprintf(
                '*%s* %s %s',
                $this->escape($actor_name),
                $this->escape(lcfirst($notification->action_label)),
                $this->escape($notification->action_target),
            ),
            'fallback_text' => "{$actor_name} ".lcfirst($notification->action_label)." {$notification->action_target}",
            'context' => $notification->board?->label,
        ];
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
     * Sends a plain text message to a channel. Returns false when Slack is not connected, or
     * when `$team_id` says the channel belongs to a workspace other than the active one.
     */
    public function notifyChannel(string $channel_id, string $message, ?string $link = null, ?string $context = null, ?string $team_id = null): bool
    {
        $installation = $this->slack_service->installation();
        if (! $installation || ($team_id && $team_id !== $installation->team_id)) {
            return false;
        }

        $this->dispatch($installation, $channel_id, $this->escape($message), $message, $link, $context);

        return true;
    }

    /**
     * Sends a plain text message to a channel of `$installation`'s workspace, whichever workspace is
     * active. Used by channel automations created with a member's own Slack connection.
     */
    public function notifyChannelIn(SlackInstallation $installation, string $channel_id, string $message, ?string $link = null, ?string $context = null): void
    {
        $this->dispatch($installation, $channel_id, $this->escape($message), $message, $link, $context);
    }

    /**
     * Sends a workspace invitation to whoever uses the invited email in the active Slack
     * workspace, with an "Accept invitation" button. The email invitation is sent either way.
     */
    public function notifyWorkspaceInvitation(WorkspaceInvitation $invitation): bool
    {
        $invitation->loadMissing(['inviter', 'workspace']);
        $inviter_name = $invitation->inviter?->full_name ?: 'Someone';
        $workspace_name = $invitation->workspace->name;

        return $this->notifyInvitee(
            email: $invitation->email,
            mrkdwn: sprintf('*%s* invited you to join the *%s* workspace', $this->escape($inviter_name), $this->escape($workspace_name)).$this->quote($invitation->message),
            fallback_text: "{$inviter_name} invited you to join the {$workspace_name} workspace",
            link: "/invitations/{$invitation->code}",
            context: $this->expiryContext($invitation->expires_at),
            button_label: 'Accept invitation',
        );
    }

    /**
     * Sends a board invitation (sharing one board or view by email) to whoever uses the invited
     * email in the active Slack workspace, with a "View invitation" button.
     */
    public function notifyBoardInvitation(BoardInvitation $invitation): bool
    {
        $invitation->loadMissing(['inviter', 'board']);
        $inviter_name = $invitation->inviter?->full_name ?: 'Someone';
        $board_name = $invitation->board->label;

        return $this->notifyInvitee(
            email: $invitation->email,
            mrkdwn: sprintf('*%s* invited you to the *%s* board', $this->escape($inviter_name), $this->escape($board_name)).$this->quote($invitation->message),
            fallback_text: "{$inviter_name} invited you to the {$board_name} board",
            link: "/board-invitations/{$invitation->code}",
            context: $this->expiryContext($invitation->expires_at),
            button_label: 'View invitation',
        );
    }

    /**
     * Tells an existing member they were added to a board from the Share dialog, which grants
     * access right away and sends no email.
     */
    public function notifyBoardAccessGranted(WorkspaceNavigationItem $board, User $member, User $actor): bool
    {
        if ($member->id === $actor->id) {
            return false;
        }

        $actor_name = $actor->full_name ?: 'Someone';

        return $this->notifyInvitee(
            email: $member->email,
            mrkdwn: sprintf('*%s* gave you access to the *%s* board', $this->escape($actor_name), $this->escape($board->label)),
            fallback_text: "{$actor_name} gave you access to the {$board->label} board",
            link: "/boards/{$board->id}",
            context: null,
            button_label: 'Open board',
        );
    }

    /**
     * Queues a direct message for whoever uses `$email` in the active Slack workspace, an account
     * or not. Returns false when nothing was queued: Slack is not connected, or an administrator
     * switched invitations off for that workspace. Who `$email` is gets resolved in the queue,
     * see {@see SendSlackInvitationJob}.
     */
    public function notifyInvitee(string $email, string $mrkdwn, string $fallback_text, string $link, ?string $context, string $button_label): bool
    {
        $installation = $this->slack_service->installation();

        if (! $installation || ! $installation->notify_invitations) {
            return false;
        }

        SendSlackInvitationJob::dispatch(
            $installation->id,
            $email,
            mb_substr($fallback_text, 0, 3000),
            $this->buildBlocks($mrkdwn, $context, $link, $button_label),
        );

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
        $link = $this->slack_service->notificationLinkFor($user);

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

    /**
     * The personal note an inviter added, as a Slack quote under the message. Empty when there is none.
     */
    private function quote(?string $message): string
    {
        $message = trim((string) $message);

        return $message === '' ? '' : "\n>".str_replace("\n", "\n>", $this->escape($message));
    }

    private function expiryContext(?DateTimeInterface $expires_at): ?string
    {
        return $expires_at ? 'This invitation expires on '.$expires_at->format('M j, Y') : null;
    }

    private function sendToUser(User $user, string $mrkdwn, string $fallback_text, ?string $link, ?string $context): bool
    {
        $slack_link = $this->slack_service->notificationLinkFor($user);

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
     * The block layout every notification uses: the message, an optional context line and an
     * optional button (`$button_label`, "Open in workspace" by default) pointing at `$link` (an in-app path).
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildBlocks(string $mrkdwn, ?string $context, ?string $link, string $button_label = 'Open in workspace'): array
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
                    'text' => ['type' => 'plain_text', 'text' => $button_label],
                    'url' => rtrim((string) config('app.frontend_url'), '/').$link,
                ]],
            ];
        }

        return $blocks;
    }
}
