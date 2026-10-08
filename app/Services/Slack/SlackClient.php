<?php

namespace App\Services\Slack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the handful of Slack Web API methods this app uses. Every call returns
 * the decoded payload of a successful (`ok: true`) response and throws a
 * {@see SlackException} otherwise, so callers never have to inspect `ok` themselves.
 */
class SlackClient
{
    private const API_BASE_URL = 'https://slack.com/api/';

    public function __construct(private readonly SlackAppCredentials $credentials) {}

    /**
     * Exchanges the "Add to Slack" authorization code for the bot token.
     *
     * @return array<string, mixed>
     */
    public function exchangeInstallCode(string $code, string $redirect_uri): array
    {
        return $this->postForm('oauth.v2.access', [
            'code' => $code,
            'redirect_uri' => $redirect_uri,
        ], with_client_credentials: true);
    }

    /**
     * Exchanges a "Sign in with Slack" authorization code for the member's access token.
     *
     * @return array<string, mixed>
     */
    public function exchangeUserCode(string $code, string $redirect_uri): array
    {
        return $this->postForm('openid.connect.token', [
            'code' => $code,
            'redirect_uri' => $redirect_uri,
            'grant_type' => 'authorization_code',
        ], with_client_credentials: true);
    }

    /**
     * The signed in member's identity: `sub` (Slack member id), `name`, `email`
     * and `https://slack.com/team_id`.
     *
     * @return array<string, mixed>
     */
    public function fetchUserInfo(string $user_access_token): array
    {
        return $this->send('openid.connect.userInfo', fn (PendingRequest $request) => $request
            ->withToken($user_access_token)
            ->get(self::API_BASE_URL.'openid.connect.userInfo'));
    }

    /**
     * Posts a message. `$channel` is a channel id, or a Slack member id to reach that
     * member through the app's direct message conversation.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    public function postMessage(string $bot_token, string $channel, string $text, array $blocks = []): array
    {
        return $this->send('chat.postMessage', fn (PendingRequest $request) => $request
            ->withToken($bot_token)
            ->asJson()
            ->post(self::API_BASE_URL.'chat.postMessage', array_filter([
                'channel' => $channel,
                'text' => $text,
                'blocks' => $blocks ?: null,
                'unfurl_links' => false,
                'unfurl_media' => false,
            ], fn ($value) => $value !== null)));
    }

    /**
     * One page of public and private channels the bot can see.
     *
     * @return array<string, mixed>
     */
    public function listChannels(string $bot_token, ?string $cursor = null): array
    {
        return $this->send('conversations.list', fn (PendingRequest $request) => $request
            ->withToken($bot_token)
            ->get(self::API_BASE_URL.'conversations.list', array_filter([
                'types' => 'public_channel,private_channel',
                'exclude_archived' => 'true',
                'limit' => 200,
                'cursor' => $cursor,
            ])));
    }

    /**
     * One page of the workspace's members, with their email address when the app holds
     * `users:read.email`. Used to match app users to Slack members by email.
     *
     * @return array<string, mixed>
     */
    public function listUsers(string $bot_token, ?string $cursor = null): array
    {
        return $this->send('users.list', fn (PendingRequest $request) => $request
            ->withToken($bot_token)
            ->get(self::API_BASE_URL.'users.list', array_filter([
                'limit' => 200,
                'cursor' => $cursor,
            ])));
    }

    /**
     * Creates a new Slack app from `$manifest`, in the workspace the app configuration token
     * was generated for. Returns `app_id` and `credentials` (`client_id`, `client_secret`,
     * `signing_secret`). The configuration token is only used for this one call, never stored.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function createAppFromManifest(string $configuration_token, array $manifest): array
    {
        return $this->send('apps.manifest.create', fn (PendingRequest $request) => $request
            ->withToken($configuration_token)
            ->asForm()
            ->post(self::API_BASE_URL.'apps.manifest.create', [
                'manifest' => json_encode($manifest, JSON_UNESCAPED_SLASHES),
            ]));
    }

    /**
     * Checks a token and returns who it belongs to: `team_id`, `team`, `user_id`, `bot_id`, `url`.
     *
     * @return array<string, mixed>
     */
    public function authTest(string $token): array
    {
        return $this->send('auth.test', fn (PendingRequest $request) => $request
            ->withToken($token)
            ->post(self::API_BASE_URL.'auth.test'));
    }

    /**
     * Invalidates a bot token. Best effort, the caller ignores failures.
     *
     * @return array<string, mixed>
     */
    public function revokeToken(string $token): array
    {
        return $this->send('auth.revoke', fn (PendingRequest $request) => $request
            ->withToken($token)
            ->post(self::API_BASE_URL.'auth.revoke'));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function postForm(string $method, array $params, bool $with_client_credentials = false): array
    {
        return $this->send($method, function (PendingRequest $request) use ($method, $params, $with_client_credentials) {
            if ($with_client_credentials) {
                $request = $request->withBasicAuth(
                    (string) $this->credentials->clientId(),
                    (string) $this->credentials->clientSecret(),
                );
            }

            return $request->asForm()->post(self::API_BASE_URL.$method, $params);
        });
    }

    /**
     * The readable messages of an `errors` list, as `apps.manifest.create` returns for an
     * invalid manifest, e.g. "Event Subscription requires either Request URL or Socket Mode Enabled".
     *
     * @return array<int, string>
     */
    private function errorDetails(mixed $payload): array
    {
        if (! is_array($payload) || ! is_array($payload['errors'] ?? null)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $error) {
            if (! is_array($error) || ! is_string($error['message'] ?? null)) {
                return null;
            }

            return is_string($error['pointer'] ?? null) ? "{$error['message']} ({$error['pointer']})" : $error['message'];
        }, $payload['errors'])));
    }

    /**
     * @param  callable(PendingRequest): Response  $request_callback
     * @return array<string, mixed>
     */
    private function send(string $method, callable $request_callback): array
    {
        try {
            $response = $request_callback(Http::timeout(10)->connectTimeout(5));
        } catch (ConnectionException $exception) {
            throw new SlackException('connection_failed', "Could not reach Slack for {$method}.");
        }

        if ($response->status() === 429) {
            throw new SlackException('ratelimited', 'Slack rate limit reached.', (int) $response->header('Retry-After') ?: 30);
        }

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload) || ($payload['ok'] ?? false) !== true) {
            $error_code = is_array($payload) && is_string($payload['error'] ?? null) ? $payload['error'] : 'http_'.$response->status();

            throw new SlackException($error_code, details: $this->errorDetails($payload));
        }

        return $payload;
    }
}
