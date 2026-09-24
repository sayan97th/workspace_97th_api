<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any request that was not signed by Slack with the app's signing secret.
 *
 * Slack signs `v0:{timestamp}:{raw body}` with HMAC SHA256 and sends the result in
 * `X-Slack-Signature`. Requests older than five minutes are refused as well, so a captured
 * request cannot be replayed later.
 *
 * @see https://api.slack.com/authentication/verifying-requests-from-slack
 */
class VerifySlackSignature
{
    private const MAX_AGE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $signing_secret = (string) config('services.slack.signing_secret');

        if ($signing_secret === '') {
            return response()->json(['message' => 'Slack is not configured on this server.'], 503);
        }

        if (! $this->hasValidSignature($request, $signing_secret)) {
            return response()->json(['message' => 'Invalid Slack signature.'], 401);
        }

        return $next($request);
    }

    private function hasValidSignature(Request $request, string $signing_secret): bool
    {
        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $signature = $request->header('X-Slack-Signature');

        if (! is_string($timestamp) || ! ctype_digit($timestamp) || ! is_string($signature)) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            return false;
        }

        $expected_signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$request->getContent()}", $signing_secret);

        return hash_equals($expected_signature, $signature);
    }
}
