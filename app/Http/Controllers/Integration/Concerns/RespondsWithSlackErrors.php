<?php

namespace App\Http\Controllers\Integration\Concerns;

use App\Services\Slack\SlackErrorMessage;
use App\Services\Slack\SlackException;
use Illuminate\Http\JsonResponse;

/**
 * Turns a {@see SlackException} into a message a person can act on, shared by every Slack controller.
 */
trait RespondsWithSlackErrors
{
    protected function errorResponse(SlackException $exception): JsonResponse
    {
        $status = in_array($exception->error_code, ['not_configured', 'connection_failed', 'ratelimited'], true) ? 503 : 422;

        return response()->json(['message' => SlackErrorMessage::describe($exception)], $status);
    }
}
