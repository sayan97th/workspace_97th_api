<?php

namespace App\Services\Slack;

use App\Models\SlackInstallation;
use App\Models\User;

/**
 * The Slack status every screen reads: whether credentials are set, which workspace is
 * active, and whether `$user` linked their own account in it. Never includes a token.
 */
class SlackStatus
{
    public function __construct(private readonly SlackAppCredentials $credentials) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $installation = SlackInstallation::current();
        $link = $installation ? $user->slackLinks()->where('slack_installation_id', $installation->id)->first() : null;

        return [
            'is_configured' => $this->credentials->isConfigured(),
            'is_connected' => $installation !== null,
            'can_manage' => $user->hasRole(['super_admin', 'admin']),
            // The Slack app is a one time developer setting only the account owner sees.
            'can_configure_app' => $user->hasRole('super_admin'),
            'credentials_source' => $this->credentials->source(),
            'workspaces_count' => SlackInstallation::count(),
            'workspace' => $installation ? [
                'id' => $installation->id,
                'team_id' => $installation->team_id,
                'team_name' => $installation->team_name,
                'team_url' => $installation->team_url,
                'connected_at' => $installation->created_at,
                'connected_by' => $installation->installedBy?->full_name,
                'linked_members_count' => $installation->userLinks()->whereHas('user')->count(),
            ] : null,
            'current_user_link' => $link ? [
                'slack_user_id' => $link->slack_user_id,
                'slack_display_name' => $link->slack_display_name,
                'linked_at' => $link->linked_at,
            ] : null,
        ];
    }
}
