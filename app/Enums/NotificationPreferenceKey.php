<?php

namespace App\Enums;

/**
 * Whitelist of notification preference keys. Labels/descriptions/categories for these
 * are pure UI copy owned by the frontend (`PROFILE_NOTIFICATION_SEED`) — this enum exists
 * only so the backend can validate incoming preference keys without hardcoding an array.
 */
enum NotificationPreferenceKey: string
{
    case Mentioned = 'mentioned';
    case WroteOwn = 'wrote_own';
    case WroteSub = 'wrote_sub';
    case RepliedThread = 'replied_thread';
    case RepliedUpdate = 'replied_update';
    case Reactions = 'reactions';
    case Assigned = 'assigned';
    case Invitations = 'invitations';
    case TemplateChanges = 'template_changes';
    case AgentFailures = 'agent_failures';
    case AutomationsNotify = 'automations_notify';
    case AutomationFailures = 'automation_failures';
    case PlatformApi = 'platform_api';
    case RequestsAccess = 'requests_access';
    case RequestsInstall = 'requests_install';
    case SignedUp = 'signed_up';
    case NotSignedUp = 'not_signed_up';
    case ViolationSummaries = 'violation_summaries';
    case FileDeleted = 'file_deleted';
    case UpdateDeleted = 'update_deleted';

    /**
     * Get all valid preference keys.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $key) => $key->value, self::cases());
    }

    /**
     * Get every valid `<key>_<channel>` preference map entry, e.g. `mentioned_app`.
     *
     * @return array<string>
     */
    public static function channelKeys(): array
    {
        $keys = [];
        foreach (self::cases() as $key) {
            $keys[] = "{$key->value}_app";
            $keys[] = "{$key->value}_email";
        }

        return $keys;
    }
}
