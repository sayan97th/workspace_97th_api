<?php

namespace App\Http\Middleware;

use App\Services\Board\AutomationEffectCollector;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Adds `automation_effects` to the JSON response of any request whose change set automations
 * off, see {@see AutomationEffectCollector::responsePayload()}. The board applies it on the same
 * round trip, so "When Status changes to Done, move item to Completed" shows the move instantly.
 *
 * Only a JSON object body is extended, a list, a file or an empty response is left as it is.
 */
class AttachAutomationEffects
{
    public function __construct(private readonly AutomationEffectCollector $effects) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || ! $response->isSuccessful() || ! $this->effects->hasEffects()) {
            return $response;
        }

        try {
            $data = $response->getData(true);
            if (! is_array($data) || array_is_list($data)) {
                return $response;
            }
            $data['automation_effects'] = $this->effects->responsePayload();
            $response->setData($data);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $response;
    }
}
