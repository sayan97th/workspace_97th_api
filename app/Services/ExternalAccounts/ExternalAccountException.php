<?php

namespace App\Services\ExternalAccounts;

use RuntimeException;

/**
 * A Google or Microsoft call that failed, with a short `error_code` the frontend maps to its own
 * copy and a message safe to show as is.
 */
class ExternalAccountException extends RuntimeException
{
    /** The provider no longer accepts the account's tokens, the member has to connect it again. */
    public const CODE_TOKEN_REVOKED = 'token_revoked';

    public const CODE_NOT_CONFIGURED = 'not_configured';

    public const CODE_INVALID_STATE = 'invalid_state';

    public const CODE_MISSING_SCOPES = 'missing_scopes';

    public const CODE_PROVIDER_ERROR = 'provider_error';

    public function __construct(public readonly string $error_code, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function isTokenRevoked(): bool
    {
        return $this->error_code === self::CODE_TOKEN_REVOKED;
    }
}
