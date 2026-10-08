<?php

namespace App\Enums;

use App\Models\Notification;

/**
 * Every test of the Slack notification test suite at /admin/test/slack/notifications. Each case
 * describes itself (label, description, category), which Slack scopes it needs and what it sends
 * to, so the frontend renders the list straight from {@see self::describe()} and the runner never
 * has to repeat that metadata.
 */
enum SlackNotificationTest: string
{
    public const CATEGORY_CONNECTION = 'connection';

    public const CATEGORY_NOTIFICATIONS = 'notifications';

    public const CATEGORY_DIRECT_MESSAGES = 'direct_messages';

    public const CATEGORY_CHANNELS = 'channels';

    public const CATEGORY_EVENTS = 'events';

    /** Runs without a recipient or a channel. */
    public const TARGET_NONE = 'none';

    /** Needs a member who linked their Slack account. */
    public const TARGET_USER = 'user';

    /** Needs a Slack channel. */
    public const TARGET_CHANNEL = 'channel';

    /** Needs both, the message goes to the channel and is about the member. */
    public const TARGET_USER_AND_CHANNEL = 'user_and_channel';

    case BotIdentity = 'bot_identity';
    case RecipientLookup = 'recipient_lookup';
    case RecipientSettings = 'recipient_settings';
    case MentionNotification = 'mention_notification';
    case AssignmentNotification = 'assignment_notification';
    case ReplyNotification = 'reply_notification';
    case ReactionNotification = 'reaction_notification';
    case DueDateReminder = 'due_date_reminder';
    case AutomationNotification = 'automation_notification';
    case QueuedNotification = 'queued_notification';
    case DirectMessage = 'direct_message';
    case RichMessage = 'rich_message';
    case ChannelMessage = 'channel_message';
    case ChannelMention = 'channel_mention';
    case EphemeralMessage = 'ephemeral_message';
    case ThreadReply = 'thread_reply';
    case MessageUpdate = 'message_update';
    case Reaction = 'reaction';
    case FileUpload = 'file_upload';
    case ScheduledMessage = 'scheduled_message';
    case AppMentionEvent = 'app_mention_event';

    public function label(): string
    {
        return match ($this) {
            self::BotIdentity => 'Bot authentication',
            self::RecipientLookup => 'Recipient Slack account',
            self::RecipientSettings => 'Recipient notification settings',
            self::MentionNotification => 'Mention notification',
            self::AssignmentNotification => 'Assignment notification',
            self::ReplyNotification => 'Reply notification',
            self::ReactionNotification => 'Reaction notification',
            self::DueDateReminder => 'Due date reminder',
            self::AutomationNotification => 'Automation notification',
            self::QueuedNotification => 'Queued delivery',
            self::DirectMessage => 'Plain direct message',
            self::RichMessage => 'Rich message layout',
            self::ChannelMessage => 'Channel message with read back',
            self::ChannelMention => 'Mention a member in a channel',
            self::EphemeralMessage => 'Ephemeral message',
            self::ThreadReply => 'Thread reply',
            self::MessageUpdate => 'Edit a sent message',
            self::Reaction => 'Add a reaction',
            self::FileUpload => 'File attachment',
            self::ScheduledMessage => 'Scheduled message',
            self::AppMentionEvent => 'App mention event',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BotIdentity => 'Calls auth.test with the bot token of the active workspace and confirms it belongs to that workspace.',
            self::RecipientLookup => 'Looks up the recipient in Slack by id and by email, confirming the account is active and matches the email used in the workspace.',
            self::RecipientSettings => 'Reads the recipient\'s notification preferences and quiet hours, the settings that can silently stop a Slack notification.',
            self::MentionNotification => 'Sends a sample "mentioned you" notification with the exact layout a real mention uses.',
            self::AssignmentNotification => 'Sends a sample "assigned you" notification with the exact layout a real assignment uses.',
            self::ReplyNotification => 'Sends a sample "replied to your update" notification with the exact layout a real reply uses.',
            self::ReactionNotification => 'Sends a sample "reacted to your update" notification with the exact layout a real reaction uses.',
            self::DueDateReminder => 'Sends a sample due date reminder, the system notification sent without an author.',
            self::AutomationNotification => 'Sends a sample automation notification, as the "notify someone" automation action does.',
            self::QueuedNotification => 'Queues a notification through the background job real notifications use, which proves the queue worker delivers it.',
            self::DirectMessage => 'Sends a plain text direct message from the app to the recipient.',
            self::RichMessage => 'Sends a Block Kit message with a header, fields, a divider, a context line and a button.',
            self::ChannelMessage => 'Posts to the channel, then reads the channel history (channels:history or groups:history) to confirm Slack stored the message.',
            self::ChannelMention => 'Posts to the channel tagging the recipient, Slack then notifies them as a mention.',
            self::EphemeralMessage => 'Posts a message in the channel that only the recipient can see. The recipient must be a member of the channel.',
            self::ThreadReply => 'Posts a parent message, then answers it in a thread, the way comment replies could be grouped.',
            self::MessageUpdate => 'Posts an "in progress" message, then edits it to "completed", as a status that changes over time.',
            self::Reaction => 'Posts a message, adds a reaction to it and reads the reactions back to confirm it was saved.',
            self::FileUpload => 'Uploads a small text file to the channel with the current upload flow, then reads its details back.',
            self::ScheduledMessage => 'Schedules a message for one minute from now, the way a reminder would be delivered later.',
            self::AppMentionEvent => 'Reports the last time someone mentioned the app in Slack. Needs Event Subscriptions with the app_mention event on a public HTTPS URL.',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::BotIdentity, self::RecipientLookup, self::RecipientSettings => self::CATEGORY_CONNECTION,
            self::MentionNotification, self::AssignmentNotification, self::ReplyNotification,
            self::ReactionNotification, self::DueDateReminder, self::AutomationNotification,
            self::QueuedNotification => self::CATEGORY_NOTIFICATIONS,
            self::DirectMessage, self::RichMessage => self::CATEGORY_DIRECT_MESSAGES,
            self::ChannelMessage, self::ChannelMention, self::EphemeralMessage, self::ThreadReply,
            self::MessageUpdate, self::Reaction, self::FileUpload, self::ScheduledMessage => self::CATEGORY_CHANNELS,
            self::AppMentionEvent => self::CATEGORY_EVENTS,
        };
    }

    public function target(): string
    {
        return match ($this) {
            self::BotIdentity, self::AppMentionEvent => self::TARGET_NONE,
            self::ChannelMessage, self::ThreadReply, self::MessageUpdate, self::Reaction,
            self::FileUpload, self::ScheduledMessage => self::TARGET_CHANNEL,
            self::ChannelMention, self::EphemeralMessage => self::TARGET_USER_AND_CHANNEL,
            default => self::TARGET_USER,
        };
    }

    public function needsRecipient(): bool
    {
        return in_array($this->target(), [self::TARGET_USER, self::TARGET_USER_AND_CHANNEL], true);
    }

    public function needsChannel(): bool
    {
        return in_array($this->target(), [self::TARGET_CHANNEL, self::TARGET_USER_AND_CHANNEL], true);
    }

    /**
     * Bot scopes the test calls Slack with. A test whose scopes the active workspace was not
     * granted is skipped instead of failing with `missing_scope`.
     *
     * @return array<int, string>
     */
    public function requiredScopes(): array
    {
        return match ($this) {
            self::BotIdentity, self::RecipientSettings => [],
            self::RecipientLookup => ['users:read', 'users:read.email'],
            // ChannelMessage only requires chat:write. Reading the message back needs channels:history
            // (public) or groups:history (private), without them it still posts and reports a warning.
            self::Reaction => ['chat:write', 'reactions:write', 'reactions:read'],
            self::FileUpload => ['files:write', 'files:read'],
            self::AppMentionEvent => ['app_mentions:read'],
            default => ['chat:write'],
        };
    }

    /**
     * The in-app notification type a sample notification test imitates, null for the other tests.
     */
    public function notificationType(): ?string
    {
        return match ($this) {
            self::MentionNotification => Notification::TYPE_MENTIONED,
            self::AssignmentNotification => Notification::TYPE_ASSIGNED,
            self::ReplyNotification => Notification::TYPE_REPLIED_UPDATE,
            self::ReactionNotification => Notification::TYPE_REACTIONS,
            self::DueDateReminder => Notification::TYPE_DUE_DATE_REMINDER,
            self::AutomationNotification => Notification::TYPE_AUTOMATION,
            default => null,
        };
    }

    /**
     * Whether running the test puts something in Slack that people can see.
     */
    public function sendsMessage(): bool
    {
        return ! in_array($this, [self::BotIdentity, self::RecipientLookup, self::RecipientSettings, self::AppMentionEvent], true);
    }

    /**
     * @return array{key: string, label: string, description: string, category: string, target: string, required_scopes: array<int, string>, sends_message: bool}
     */
    public function describe(): array
    {
        return [
            'key' => $this->value,
            'label' => $this->label(),
            'description' => $this->description(),
            'category' => $this->category(),
            'target' => $this->target(),
            'required_scopes' => $this->requiredScopes(),
            'sends_message' => $this->sendsMessage(),
        ];
    }
}
