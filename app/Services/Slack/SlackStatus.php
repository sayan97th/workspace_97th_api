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
    /** Roles allowed to set up the Slack app and manage workspaces, the same `role:` gate as the routes. */
    public const MANAGER_ROLES = ['super_admin', 'admin'];

    /** Frontend page where the Slack app and workspaces are set up, Administration > Integrations > Slack. */
    public const SETUP_PATH = '/administration/integrations/slack';

    public function __construct(
        private readonly SlackAppCredentials $credentials,
        private readonly SlackService $slack_service,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $installation = SlackInstallation::current();
        $link = $installation ? $user->slackLinks()->where('slack_installation_id', $installation->id)->first() : null;

        $is_configured = $this->credentials->isConfigured();
        $can_manage = $user->hasRole(self::MANAGER_ROLES);

        return [
            'is_configured' => $is_configured,
            'is_connected' => $installation !== null,
            // Slack is ready for "Connect my Slack" only once the app is set up and a workspace is connected.
            'needs_setup' => ! $is_configured || $installation === null,
            'setup_path' => self::SETUP_PATH,
            'can_manage' => $can_manage,
            // Which Slack page "Connect my Slack" opens, so the UI can tell the member what to expect.
            'link_method' => $this->slack_service->linkMethod(),
            // The Slack app is a one time setup done by an administrator or the account owner.
            'can_configure_app' => $can_manage,
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
