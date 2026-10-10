<?php

namespace App\Http\Controllers\Integration;

use App\Enums\ExternalService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\CalendarIndexRequest;
use App\Http\Requests\Integration\ExternalAccountConnectRequest;
use App\Http\Requests\Integration\ExternalAccountIndexRequest;
use App\Models\ExternalAccount;
use App\Services\ExternalAccounts\ExternalAccountException;
use App\Services\ExternalAccounts\ExternalAccountService;
use App\Services\ExternalAccounts\GoogleCalendarClient;
use App\Support\AccountPermissions;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A member's own Gmail, Outlook and Google Calendar accounts, the "Connect your Gmail account" step
 * of the Automations center's recipes. Every endpoint only ever sees the caller's accounts.
 */
class ExternalAccountController extends Controller
{
    public function __construct(private readonly ExternalAccountService $accounts) {}

    /**
     * GET /api/integrations/accounts?service=gmail
     *
     * The caller's accounts that can be used for the service, the newest first, with how many
     * automations use each, plus whether the provider's app is set up and they may connect one.
     */
    public function index(ExternalAccountIndexRequest $request): JsonResponse
    {
        $user = $request->user();
        $service = $request->service();
        $accounts = $this->accounts->accountsFor($user, $service);
        $usage = $this->accounts->automationCounts($accounts->modelKeys());
        $is_configured = $service ? $this->accounts->isConfigured($service) : true;

        return response()->json([
            'data' => $accounts->map(fn (ExternalAccount $account) => $this->present($account, $usage[$account->id] ?? 0))->values(),
            'is_configured' => $is_configured,
            'can_connect' => $is_configured && AccountPermissions::allows($user, AccountPermissions::USE_INTEGRATIONS),
        ]);
    }

    /**
     * POST /api/integrations/accounts/url
     *
     * The provider's consent page, fetched by the frontend and opened in a new tab since a top
     * level navigation cannot carry the JWT.
     */
    public function url(ExternalAccountConnectRequest $request): JsonResponse
    {
        try {
            return response()->json(['url' => $this->accounts->buildConnectUrl($request->user(), $request->service(), $request->validated('return_path'), $request->display())]);
        } catch (ExternalAccountException $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * GET /api/integrations/accounts/{account}/calendars
     *
     * The Google calendars the account may add events to, for the recipe's calendar picker.
     */
    public function calendars(CalendarIndexRequest $request, ExternalAccount $account, GoogleCalendarClient $calendar): JsonResponse
    {
        $this->ensureOwnedBy($request, $account);

        if (! $account->supports(ExternalService::GoogleCalendar)) {
            return response()->json(['message' => 'This account was not connected with Google Calendar permissions. Connect it again from a Google Calendar recipe.', 'code' => ExternalAccountException::CODE_MISSING_SCOPES], 422);
        }

        try {
            return response()->json(['data' => $calendar->writableCalendars($account, $request->wantsFresh())]);
        } catch (ExternalAccountException $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * DELETE /api/integrations/accounts/{account}
     */
    public function destroy(Request $request, ExternalAccount $account): JsonResponse
    {
        $this->ensureOwnedBy($request, $account);

        $label = $account->provider->label();
        $usage = $this->accounts->automationCounts([$account->id])[$account->id] ?? 0;

        $this->accounts->disconnect($account);
        AuditLogger::log('integrations.account_disconnected', "Disconnected the {$label} account {$account->email} from automations.", $request->user(), [
            'account_id' => $account->id,
            'provider' => $account->provider->value,
        ]);

        $message = $usage > 0
            ? "Your {$label} account {$account->email} was disconnected. {$usage} ".($usage === 1 ? 'automation stops' : 'automations stop').' working until you pick another account.'
            : "Your {$label} account {$account->email} was disconnected.";

        return response()->json(['message' => $message]);
    }

    /**
     * An account that belongs to someone else answers 404, so ids cannot be probed.
     */
    private function ensureOwnedBy(Request $request, ExternalAccount $account): void
    {
        abort_unless($account->user_id === $request->user()->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ExternalAccount $account, int $automations_count): array
    {
        return [
            'id' => $account->id,
            'provider' => $account->provider->value,
            'email' => $account->email,
            'name' => $account->name,
            'services' => $account->serviceValues(),
            'last_error' => $account->last_error,
            'connected_at' => $account->connected_at,
            'automations_count' => $automations_count,
        ];
    }

    private function errorResponse(ExternalAccountException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage(), 'code' => $exception->error_code], $exception->status);
    }
}
