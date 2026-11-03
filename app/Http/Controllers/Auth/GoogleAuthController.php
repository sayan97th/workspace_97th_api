<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Teams\CreateTeam;
use App\Actions\Workspaces\JoinPendingWorkspaceInvitations;
use App\Concerns\IssuesJwtTokens;
use App\Concerns\StartsTwoFactorChallenges;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleExchangeRequest;
use App\Http\Requests\Auth\GoogleRedirectRequest;
use App\Jobs\SendEmailJob;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\Workspace\HomeWorkspaceEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Sign in and sign up with Google.
 *
 * 1. `GET  /api/auth/google/redirect` stores a random OAuth `state` (with the
 *    "Keep me logged in" choice and the page to return to) and sends the
 *    browser to Google.
 * 2. `GET  /api/auth/google/callback` is where Google sends the browser back.
 *    The `state` is checked (login CSRF protection), the account is found,
 *    linked or created, and the browser is sent to the frontend with a one
 *    time `login_code`. The access token itself never travels in a URL, so it
 *    can't leak through browser history, server logs or the Referer header.
 * 3. `POST /api/auth/google/exchange` trades that code for an access token,
 *    or for a two factor challenge when the account has 2FA turned on.
 */
class GoogleAuthController extends Controller
{
    use IssuesJwtTokens, StartsTwoFactorChallenges;

    /**
     * How long the OAuth `state` waits for Google to send the user back.
     */
    private const STATE_TTL_MINUTES = 10;

    /**
     * How long the frontend has to exchange the one time login code. It is
     * redeemed right after the redirect, so this only has to cover a page load.
     */
    private const LOGIN_CODE_TTL_SECONDS = 60;

    public function __construct(
        private CreateTeam $createTeam,
        private HomeWorkspaceEnrollmentService $homeWorkspaceEnrollmentService,
        private JoinPendingWorkspaceInvitations $joinPendingWorkspaceInvitations,
    ) {
        //
    }

    /**
     * GET /api/auth/google/redirect?remember=1&callback_url=/some/page
     *
     * The flow is stateless (no cookie session on the API), so the `state` is
     * generated here, kept in the cache and verified in {@see callback()}.
     * Socialite skips its own `state` field in stateless mode, which is why it
     * is passed as a custom parameter.
     */
    public function redirect(GoogleRedirectRequest $request): RedirectResponse
    {
        $state = Str::random(40);

        Cache::put(
            "google_oauth_state:{$state}",
            [
                'remember' => $request->boolean('remember'),
                'callback_url' => $request->validated('callback_url'),
            ],
            now()->addMinutes(self::STATE_TTL_MINUTES),
        );

        return $this->provider()
            ->stateless()
            ->with(['state' => $state, 'prompt' => 'select_account'])
            ->redirect();
    }

    /**
     * GET /api/auth/google/callback
     */
    public function callback(Request $request): RedirectResponse
    {
        // The user pressed "Cancel" on Google's consent screen.
        if ($request->filled('error')) {
            return $this->redirectToSignIn('google_auth_cancelled');
        }

        $state = $request->string('state')->toString();
        $state_payload = $state !== '' ? Cache::pull("google_oauth_state:{$state}") : null;

        if (! \is_array($state_payload)) {
            return $this->redirectToSignIn('google_auth_expired');
        }

        try {
            $google_user = $this->provider()->stateless()->user();
        } catch (\Throwable $e) {
            Log::error('Google OAuth callback error', ['message' => $e->getMessage()]);

            return $this->redirectToSignIn('google_auth_failed');
        }

        $google_id = (string) $google_user->getId();
        $email = Str::lower((string) $google_user->getEmail());

        if ($google_id === '' || $email === '') {
            return $this->redirectToSignIn('google_auth_failed');
        }

        if (! $this->hasVerifiedEmail($google_user)) {
            return $this->redirectToSignIn('google_email_unverified');
        }

        $user = User::where('google_id', $google_id)->first()
            ?? User::where('email', $email)->first();

        if ($user) {
            // The email belongs to an account already linked to another Google
            // account, for example a recycled address. Never relink it silently.
            if ($user->google_id && $user->google_id !== $google_id) {
                return $this->redirectToSignIn('google_account_mismatch');
            }

            if (! $user->is_active) {
                return $this->redirectToSignIn('account_disabled');
            }

            if (! $user->google_id) {
                $user->update(['google_id' => $google_id]);
            }
        } else {
            $user = $this->createUser($google_user, $google_id, $email);
        }

        $login_code = Str::random(64);

        Cache::put(
            "google_oauth_login:{$login_code}",
            [
                'user_id' => $user->id,
                'remember' => (bool) ($state_payload['remember'] ?? false),
                'callback_url' => $state_payload['callback_url'] ?? null,
            ],
            now()->addSeconds(self::LOGIN_CODE_TTL_SECONDS),
        );

        return redirect($this->frontendUrl('/auth/google/callback?'.http_build_query(['login_code' => $login_code])));
    }

    /**
     * POST /api/auth/google/exchange
     *
     * Trades the one time `login_code` from {@see callback()} for an access
     * token. Accounts with two factor authentication still have to pass the
     * challenge, exactly like a password sign in.
     */
    public function exchange(GoogleExchangeRequest $request): JsonResponse
    {
        $login_payload = Cache::pull("google_oauth_login:{$request->validated('login_code')}");

        if (! \is_array($login_payload) || ! \is_int($login_payload['user_id'] ?? null)) {
            return response()->json([
                'message' => 'Your Google sign in link has expired. Please try again.',
                'code' => 'google_auth_expired',
            ], 422);
        }

        $user = User::find($login_payload['user_id']);

        if (! $user || ! $user->is_active) {
            return response()->json([
                'message' => 'Your account has been disabled. Please contact support if you believe this is a mistake.',
                'code' => 'account_disabled',
            ], 403);
        }

        $remember = (bool) $login_payload['remember'];
        $callback_url = $login_payload['callback_url'];

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return response()->json([
                'requires_two_factor' => true,
                'two_factor_token' => $this->startTwoFactorChallenge($user, $remember),
                'callback_url' => $callback_url,
            ]);
        }

        $response = $this->respondWithToken($this->issueToken($user, $remember), $user);

        return $response->setData([...$response->getData(true), 'callback_url' => $callback_url]);
    }

    private function createUser(SocialiteUserContract $google_user, string $google_id, string $email): User
    {
        return DB::transaction(function () use ($google_user, $google_id, $email) {
            [$first_name, $last_name] = $this->resolveNameParts($google_user);

            $user = User::create([
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'google_id' => $google_id,
                'password' => Str::random(32),
                'email_verified_at' => now(),
            ]);

            $this->createTeam->handle($user, $user->full_name."'s Team", isPersonal: true);

            $user->assignRole('client');
            $this->homeWorkspaceEnrollmentService->enroll($user);

            $this->joinPendingWorkspaceInvitations->handle($user);

            SendEmailJob::dispatch(new WelcomeMail($user), $user->email);

            return $user;
        });
    }

    /**
     * Google reports whether it verified the address in the `email_verified`
     * claim. Only verified addresses may create or link an account, otherwise
     * anyone could claim someone else's email.
     */
    private function hasVerifiedEmail(SocialiteUserContract $google_user): bool
    {
        if (! $google_user instanceof SocialiteUser) {
            return false;
        }

        return filter_var($google_user->user['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Resolve the first and last name from the Google OAuth profile.
     *
     * Google's raw profile payload exposes `given_name`/`family_name`
     * directly, which is more reliable than splitting the display name.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveNameParts(SocialiteUserContract $google_user): array
    {
        $given_name = null;
        $family_name = null;

        if ($google_user instanceof SocialiteUser) {
            $given_name = $google_user->user['given_name'] ?? null;
            $family_name = $google_user->user['family_name'] ?? null;
        }

        if (\is_string($given_name) && $given_name !== '') {
            return [$given_name, \is_string($family_name) ? $family_name : ''];
        }

        $display_name = $google_user->getName()
            ?: $google_user->getNickname()
            ?: Str::before((string) $google_user->getEmail(), '@');

        $parts = preg_split('/\s+/', trim($display_name), 2) ?: [$display_name];

        return [$parts[0] !== '' ? $parts[0] : $display_name, $parts[1] ?? ''];
    }

    private function redirectToSignIn(string $error_code): RedirectResponse
    {
        return redirect($this->frontendUrl('/signin?'.http_build_query(['error' => $error_code])));
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }

    /**
     * Resolve the Google OAuth provider.
     *
     * No native return type: in tests, `Socialite::fake()` swaps this driver
     * for a `FakeProvider` that only shares Socialite's base `Provider`
     * contract with `AbstractProvider`, but still supports `stateless()` via
     * `__call` forwarding.
     *
     * @return AbstractProvider
     */
    private function provider()
    {
        return Socialite::driver('google');
    }
}
