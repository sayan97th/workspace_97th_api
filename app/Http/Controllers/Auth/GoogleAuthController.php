<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Teams\CreateTeam;
use App\Concerns\IssuesJwtTokens;
use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\Workspace\HomeWorkspaceEnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class GoogleAuthController extends Controller
{
    use IssuesJwtTokens;

    public function __construct(
        private CreateTeam $createTeam,
        private HomeWorkspaceEnrollmentService $homeWorkspaceEnrollmentService
    ) {
        //
    }

    /**
     * How long the "Keep me logged in" choice waits for Google to send the user back.
     */
    private const REMEMBER_STATE_TTL_MINUTES = 10;

    /**
     * GET /api/auth/google/redirect?remember=1
     *
     * The flow is stateless (no cookie session on the API), so the sign in form's
     * "Keep me logged in" choice travels as an opaque OAuth `state` value that
     * Google echoes back to {@see callback()}.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $provider = $this->provider()->stateless();

        if ($request->boolean('remember')) {
            $state = Str::random(40);
            Cache::put("google_oauth_remember:{$state}", true, now()->addMinutes(self::REMEMBER_STATE_TTL_MINUTES));
            $provider->with(['state' => $state]);
        }

        return $provider->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $frontend_url = rtrim(config('app.frontend_url'), '/');

        try {
            $google_user = $this->provider()->stateless()->user();
        } catch (\Throwable $e) {
            Log::error('Google OAuth callback error', ['message' => $e->getMessage()]);

            return redirect("{$frontend_url}/signin?error=google_auth_failed");
        }

        $user = User::where('google_id', $google_user->getId())
            ->orWhere('email', $google_user->getEmail())
            ->first();

        if ($user) {
            if (! $user->google_id) {
                $user->update(['google_id' => $google_user->getId()]);
            }

            if (! $user->is_active) {
                return redirect("{$frontend_url}/signin?error=account_disabled");
            }
        } else {
            $user = DB::transaction(function () use ($google_user) {
                [$first_name, $last_name] = $this->resolveNameParts($google_user);

                $user = User::create([
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $google_user->getEmail(),
                    'google_id' => $google_user->getId(),
                    'password' => Str::random(32),
                    'email_verified_at' => now(),
                ]);

                $this->createTeam->handle($user, $user->full_name."'s Team", isPersonal: true);

                $user->assignRole('client');
                $this->homeWorkspaceEnrollmentService->enroll($user);

                SendEmailJob::dispatch(new WelcomeMail($user), $user->email);

                return $user;
            });
        }

        $state = $request->string('state')->toString();
        $remember = $state !== '' && Cache::pull("google_oauth_remember:{$state}") === true;

        $token = $this->issueToken($user, $remember);
        $session_expires_at = $this->sessionExpiresAt(JWTAuth::setToken($token)->getPayload());

        return redirect("{$frontend_url}/auth/google/callback?".http_build_query([
            'token' => $token,
            'expires_in' => $this->guard()->factory()->getTTL() * 60,
            'session_expires_at' => $session_expires_at->toIso8601String(),
        ]));
    }

    /**
     * Resolve the first and last name from the Google OAuth profile.
     *
     * Google's raw profile payload exposes `given_name`/`family_name`
     * directly, which is more reliable than splitting the display name.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveNameParts(\Laravel\Socialite\Contracts\User $google_user): array
    {
        $given_name = null;
        $family_name = null;

        if ($google_user instanceof \Laravel\Socialite\Two\User) {
            $given_name = $google_user->user['given_name'] ?? null;
            $family_name = $google_user->user['family_name'] ?? null;
        }

        if (\is_string($given_name) && $given_name !== '') {
            return [$given_name, \is_string($family_name) ? $family_name : ''];
        }

        $display_name = $google_user->getName()
            ?: $google_user->getNickname()
            ?: Str::before($google_user->getEmail(), '@');

        $parts = preg_split('/\s+/', trim($display_name), 2) ?: [$display_name];

        return [$parts[0] !== '' ? $parts[0] : $display_name, $parts[1] ?? ''];
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
