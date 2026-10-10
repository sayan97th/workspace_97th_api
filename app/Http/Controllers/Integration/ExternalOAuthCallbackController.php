<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Services\ExternalAccounts\ExternalAccountException;
use App\Services\ExternalAccounts\ExternalAccountService;
use App\Support\AccountPermissions;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GET /api/integrations/accounts/callback
 *
 * The one redirect URI Google and Microsoft send the browser back to. Public on purpose, the
 * browser arrives with no JWT, so who the request is for comes only from the single use `state`
 * value issued when the flow started, see {@see ExternalAccountService::consumeState()}.
 *
 * A flow opened in its own tab lands on `/integrations/accounts/complete`, which tells the tab
 * that started it how it went and closes itself, a flow that took over the page goes back to
 * where it started.
 */
class ExternalOAuthCallbackController extends Controller
{
    private const TAB_COMPLETE_PATH = '/integrations/accounts/complete';

    private const AUTOMATIONS_PATH = '/automations';

    public function __invoke(Request $request, ExternalAccountService $accounts): RedirectResponse
    {
        try {
            $context = $accounts->consumeState($request->query('state'));
        } catch (ExternalAccountException $exception) {
            return $this->redirectToFrontend(self::TAB_COMPLETE_PATH, [], 'error', $exception->error_code);
        }

        $service = $context['service'];
        $user = $context['user'];
        $is_tab = $context['display'] === ExternalAccountService::DISPLAY_TAB;
        $destination = $is_tab ? self::TAB_COMPLETE_PATH : ($context['return_path'] ?? self::AUTOMATIONS_PATH);
        $query = $is_tab ? ['service' => $service->value] : [];

        $code = $request->query('code');
        if ($request->query('error') || ! is_string($code) || $code === '') {
            return $this->redirectToFrontend($destination, $query, 'error', 'access_denied');
        }

        if (! AccountPermissions::allows($user, AccountPermissions::USE_INTEGRATIONS)) {
            return $this->redirectToFrontend($destination, $query, 'error', 'forbidden');
        }

        try {
            $account = $accounts->completeConnection($code, $user, $service);
        } catch (ExternalAccountException $exception) {
            Log::warning('External account OAuth callback failed', ['service' => $service->value, 'error' => $exception->error_code]);

            return $this->redirectToFrontend($destination, $query, 'error', $exception->error_code);
        }

        AuditLogger::log('integrations.account_connected', "Connected the {$service->label()} account {$account->email} for automations.", $user, [
            'account_id' => $account->id,
            'service' => $service->value,
        ]);

        // The Automations center selects the account it just connected.
        if ($is_tab) {
            $query['account_id'] = (string) $account->id;
            $query['email'] = (string) $account->email;
        }

        return $this->redirectToFrontend($destination, $query, 'connected');
    }

    /**
     * @param  array<string, string>  $query
     */
    private function redirectToFrontend(string $path, array $query, string $result, ?string $reason = null): RedirectResponse
    {
        $frontend_url = rtrim((string) config('app.frontend_url'), '/');
        $query = [...$query, 'result' => $result];

        if ($reason) {
            $query['reason'] = $reason;
        }

        $separator = str_contains($path, '?') ? '&' : '?';

        return redirect("{$frontend_url}{$path}{$separator}".http_build_query($query));
    }
}
