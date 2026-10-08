<?php

namespace App\Services\Slack;

use App\Models\SlackAppSetting;
use App\Models\User;

/**
 * Creates the account's Slack app in one step, so the owner never copies a manifest or three
 * credentials by hand. Slack only allows creating an app with an app configuration token,
 * which the owner generates once at api.slack.com/apps for the workspace the app should live
 * in. The token is used for this single call and never stored.
 *
 * Public distribution, needed to add the app to more than one workspace, cannot be switched on
 * through Slack's API, so the result links to that page of the new app.
 */
class SlackAppProvisioner
{
    public function __construct(
        private readonly SlackClient $client,
        private readonly SlackAppCredentials $credentials,
    ) {}

    /**
     * @throws SlackException
     */
    public function createApp(string $configuration_token, User $actor): SlackAppSetting
    {
        $payload = $this->client->createAppFromManifest($configuration_token, $this->credentials->manifest());

        $app_id = $payload['app_id'] ?? null;
        $client_id = $payload['credentials']['client_id'] ?? null;
        $client_secret = $payload['credentials']['client_secret'] ?? null;
        $signing_secret = $payload['credentials']['signing_secret'] ?? null;

        if (! is_string($app_id) || ! is_string($client_id) || ! is_string($client_secret)) {
            throw new SlackException('invalid_response', 'Slack did not return the new app credentials.');
        }

        return $this->credentials->storeCreatedApp($app_id, $client_id, $client_secret, is_string($signing_secret) ? $signing_secret : null, $actor);
    }
}
