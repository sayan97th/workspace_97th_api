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
     * member through the app's direct message conversation. `$options` adds any other
     * chat.postMessage argument, such as `thread_ts` to answer in a thread.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function postMessage(string $bot_token, string $channel, string $text, array $blocks = [], array $options = []): array
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
                ...$options,
            ], fn ($value) => $value !== null)));
    }

    /**
     * Posts a message in `$channel` that only `$slack_user_id` can see. Slack answers
     * `user_not_in_channel` when that member is not in the channel.
     *
     * @return array<string, mixed>
     */
    public function postEphemeral(string $bot_token, string $channel, string $slack_user_id, string $text): array
    {
        return $this->postJson('chat.postEphemeral', $bot_token, [
            'channel' => $channel,
            'user' => $slack_user_id,
            'text' => $text,
        ]);
    }

    /**
     * Replaces the text of a message the bot posted earlier, identified by its `ts`.
     *
     * @return array<string, mixed>
     */
    public function updateMessage(string $bot_token, string $channel, string $ts, string $text): array
    {
        return $this->postJson('chat.update', $bot_token, [
            'channel' => $channel,
            'ts' => $ts,
            'text' => $text,
        ]);
    }

    /**
     * Schedules a message for `$post_at` (a Unix timestamp in the future). Returns
     * `scheduled_message_id` and `post_at`.
     *
     * @return array<string, mixed>
     */
    public function scheduleMessage(string $bot_token, string $channel, int $post_at, string $text): array
    {
        return $this->postJson('chat.scheduleMessage', $bot_token, [
            'channel' => $channel,
            'post_at' => $post_at,
            'text' => $text,
        ]);
    }

    /**
     * The https://<workspace>.slack.com link that opens one message.
     *
     * @return array<string, mixed>
     */
    public function getPermalink(string $bot_token, string $channel, string $ts): array
    {
        return $this->getJson('chat.getPermalink', $bot_token, ['channel' => $channel, 'message_ts' => $ts]);
    }

    /**
     * Adds the `$name` emoji (without colons, e.g. `white_check_mark`) to a message.
     *
     * @return array<string, mixed>
     */
    public function addReaction(string $bot_token, string $channel, string $ts, string $name): array
    {
        return $this->postJson('reactions.add', $bot_token, ['channel' => $channel, 'timestamp' => $ts, 'name' => $name]);
    }

    /**
     * The reactions on one message, under `message.reactions`.
     *
     * @return array<string, mixed>
     */
    public function getReactions(string $bot_token, string $channel, string $ts): array
    {
        return $this->getJson('reactions.get', $bot_token, ['channel' => $channel, 'timestamp' => $ts, 'full' => 'true']);
    }

    /**
     * The messages of a channel between `$oldest` and `$latest`, both included, under `messages`.
     *
     * @return array<string, mixed>
     */
    public function conversationHistory(string $bot_token, string $channel, string $oldest, string $latest, int $limit = 1): array
    {
        return $this->getJson('conversations.history', $bot_token, [
            'channel' => $channel,
            'oldest' => $oldest,
            'latest' => $latest,
            'inclusive' => 'true',
            'limit' => $limit,
        ]);
    }

    /**
     * One member's profile, under `user`. Needs `users:read`.
     *
     * @return array<string, mixed>
     */
    public function userInfo(string $bot_token, string $slack_user_id): array
    {
        return $this->getJson('users.info', $bot_token, ['user' => $slack_user_id]);
    }

    /**
     * The member who uses `$email` in the workspace, under `user`. Needs `users:read.email`
     * and answers `users_not_found` when nobody does.
     *
     * @return array<string, mixed>
     */
    public function lookupUserByEmail(string $bot_token, string $email): array
    {
        return $this->getJson('users.lookupByEmail', $bot_token, ['email' => $email]);
    }

    /**
     * Uploads `$contents` as a file shared in `$channel`, with Slack's current three step flow:
     * ask for an upload URL, send the bytes there, then complete the upload into the channel.
     * `files.upload` was retired by Slack, so it is not used. Returns the completed file.
     *
     * @return array<string, mixed>
     */
    public function uploadFile(string $bot_token, string $channel, string $filename, string $contents, string $title, ?string $initial_comment = null): array
    {
        $upload = $this->send('files.getUploadURLExternal', fn (PendingRequest $request) => $request
            ->withToken($bot_token)
            ->asForm()
            ->post(self::API_BASE_URL.'files.getUploadURLExternal', [
                'filename' => $filename,
                'length' => strlen($contents),
            ]));

        try {
            $response = Http::timeout(15)->connectTimeout(5)
                ->withBody($contents, 'application/octet-stream')
                ->post((string) $upload['upload_url']);
        } catch (ConnectionException) {
            throw new SlackException('connection_failed', 'Could not reach Slack to upload the file.');
        }

        if (! $response->successful()) {
            throw new SlackException('upload_failed', "Slack did not accept the file contents (HTTP {$response->status()}).");
        }

        $completed = $this->postJson('files.completeUploadExternal', $bot_token, array_filter([
            'files' => [['id' => $upload['file_id'], 'title' => $title]],
            'channel_id' => $channel,
            'initial_comment' => $initial_comment,
        ], fn ($value) => $value !== null));

        return is_array($completed['files'][0] ?? null) ? $completed['files'][0] : ['id' => $upload['file_id']];
    }

    /**
     * One file's details, under `file`. Needs `files:read`.
     *
     * @return array<string, mixed>
     */
    public function fileInfo(string $bot_token, string $file_id): array
    {
        return $this->getJson('files.info', $bot_token, ['file' => $file_id]);
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
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postJson(string $method, string $token, array $body): array
    {
        return $this->send($method, fn (PendingRequest $request) => $request
            ->withToken($token)
            ->asJson()
            ->post(self::API_BASE_URL.$method, $body));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function getJson(string $method, string $token, array $query): array
    {
        return $this->send($method, fn (PendingRequest $request) => $request
            ->withToken($token)
            ->get(self::API_BASE_URL.$method, $query));
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
