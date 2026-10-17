<?php

namespace App\Services\Slack;

use App\Models\SlackInstallation;
use App\Models\User;
use App\Support\AccountPermissions;

/**
 * The Slack status every screen reads: whether credentials are set, which workspace is
 * active, and whether `$user` linked their own account in it. Never includes a token.
 */
class SlackStatus
{
    /** Roles allowed to set up the Slack app and manage workspaces, the same `role:` gate as the routes. */
    public const MANAGER_ROLES = ['super_admin', 'admin'];

    /** Frontend page where the Slack app and workspaces are set up, Administration > Connections > Slack settings. */
    public const SETUP_PATH = '/administration?section=slack';

    /**
     * Frontend page where any member sets up Slack for their own account, My Profile > Integrations.
     * It only uses the Slack app chosen by an administrator, it can never change it.
     */
    public const PERSONAL_SETUP_PATH = '/profile?section=integrations';

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
        $link = $this->slack_service->notificationLinkFor($user);

        $is_configured = $this->credentials->isConfigured();
        $can_manage = $user->hasRole(self::MANAGER_ROLES);

        return [
            'is_configured' => $is_configured,
            'is_connected' => $installation !== null,
            // Slack is ready for "Connect my Slack" only once the app is set up and a workspace is connected.
            'needs_setup' => ! $is_configured || $installation === null,
            'setup_path' => self::SETUP_PATH,
            // Only a missing Slack app needs an administrator. Without a workspace, any member can
            // still set up Slack for their own account with the app in use.
            'needs_app_setup' => ! $is_configured,
            'personal_setup_path' => self::PERSONAL_SETUP_PATH,
            'can_connect_account' => $is_configured && AccountPermissions::allows($user, AccountPermissions::USE_INTEGRATIONS),
            'connections_count' => $user->slackConnections()->count(),
            'can_manage' => $can_manage,
            // Which Slack page "Connect my Slack" opens, so the UI can tell the member what to expect.
            'link_method' => $this->slack_service->linkMethod(),
            // The Slack app is a one time setup done by an administrator or the account owner.
            'can_configure_app' => $can_manage,
            // `environment` is the app from the API .env file, `database` an app saved from Administration.
            'credentials_source' => $this->credentials->source(),
            'app_name' => $this->credentials->appName(),
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
            // The Slack account the caller's direct messages go to, in the active workspace or in
            // a workspace they connected themselves, see `SlackService::notificationLinkFor()`.
            'current_user_link' => $link ? [
                'slack_user_id' => $link->slack_user_id,
                'slack_display_name' => $link->slack_display_name,
                'linked_at' => $link->linked_at,
                'team_id' => $link->installation->team_id,
                'team_name' => $link->installation->team_name,
                'team_url' => $link->installation->team_url,
                'is_active_workspace' => $link->installation->is_active,
            ] : null,
        ];
    }
}
