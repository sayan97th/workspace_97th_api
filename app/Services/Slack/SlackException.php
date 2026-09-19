<?php

namespace App\Services\Slack;

use RuntimeException;

/**
 * Raised for every Slack failure, both errors reported by the Slack Web API (`ok: false`,
 * HTTP 429, transport errors) and integration level problems such as an expired OAuth state.
 * `error_code` is Slack's own error string when there is one, e.g. `channel_not_found`.
 */
class SlackException extends RuntimeException
{
    /**
     * Errors that mean the installation's token is dead, so retrying can never succeed.
     *
     * @var array<int, string>
     */
    private const TOKEN_ERRORS = ['invalid_auth', 'token_revoked', 'token_expired', 'account_inactive', 'not_authed'];

    /**
     * Errors that will fail the same way on every retry, so a queued job should give up.
     *
     * @var array<int, string>
     */
    private const PERMANENT_ERRORS = [
        'channel_not_found', 'not_in_channel', 'is_archived', 'user_not_found', 'user_disabled',
        'missing_scope', 'restricted_action', 'msg_too_long', 'invalid_blocks',
    ];

    public function __construct(
        public readonly string $error_code,
        string $message = '',
        public readonly ?int $retry_after = null,
    ) {
        parent::__construct($message !== '' ? $message : "Slack error: {$error_code}");
    }

    public function isRateLimited(): bool
    {
        return $this->error_code === 'ratelimited';
    }

    public function isTokenInvalid(): bool
    {
        return in_array($this->error_code, self::TOKEN_ERRORS, true);
    }

    public function isPermanent(): bool
    {
        return in_array($this->error_code, self::PERMANENT_ERRORS, true);
    }
}
