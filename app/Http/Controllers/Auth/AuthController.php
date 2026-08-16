<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Teams\CreateTeam;
use App\Concerns\IssuesJwtTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Resources\ProfileResource;
use App\Jobs\SendEmailJob;
use App\Mail\TwoFactorCodeMail;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Models\UserSession;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class AuthController extends Controller
{
    use IssuesJwtTokens;

    /**
     * How long an emailed two factor code, and the matching pending login
     * token, stay valid before the user has to sign in again.
     */
    private const TWO_FACTOR_EMAIL_CODE_TTL_MINUTES = 10;

    public function __construct(private CreateTeam $createTeam)
    {
        //
    }

    /**
     * POST /api/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            $this->createTeam->handle($user, $user->full_name."'s Team", isPersonal: true);

            $user->assignRole('client');

            $this->joinPendingWorkspaceInvitations($user);

            SendEmailJob::dispatch(new WelcomeMail($user), $user->email);

            return $user;
        });

        $token = $this->guard()->login($user);

        return $this->respondWithToken($token, $user);
    }

    /**
     * Auto-joins a freshly registered user to every workspace they were
     * invited to before they had an account, so people who sign up on their
     * own (rather than through the emailed invitation link) still land in
     * the workspace without needing to be re-invited. Mirrors the attach
     * step in {@see WorkspaceInvitationController::accept()}.
     */
    private function joinPendingWorkspaceInvitations(User $user): void
    {
        $pending_invitations = WorkspaceInvitation::where('email', $user->email)
            ->whereNull('accepted_at')
            ->get()
            ->filter(fn (WorkspaceInvitation $invitation) => $invitation->isPending());

        foreach ($pending_invitations as $invitation) {
            $invitation->workspace->users()->syncWithoutDetaching([
                $user->id => ['role' => $invitation->role, 'is_recent' => true, 'invited_by' => $invitation->invited_by],
            ]);

            $invitation->update(['accepted_at' => now()]);
        }
    }

    /**
     * POST /api/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $existing_user = User::where('email', $credentials['email'])->first();

        if ($existing_user && ! $existing_user->is_active) {
            return response()->json([
                'message' => 'Your account has been disabled. Please contact support if you believe this is a mistake.',
                'code' => 'account_disabled',
            ], 403);
        }

        $token = $this->guard()->attempt($credentials);

        if (! is_string($token)) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
                'errors' => [
                    'email' => ['The provided credentials are incorrect.'],
                ],
            ], 422);
        }

        /** @var User $user */
        $user = $this->guard()->user();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->guard()->logout();

            $two_factor_token = Str::uuid()->toString();
            $email_code = (string) random_int(100000, 999999);

            Cache::put(
                "two_factor_pending:{$two_factor_token}",
                $user->id,
                now()->addMinutes(self::TWO_FACTOR_EMAIL_CODE_TTL_MINUTES),
            );
            Cache::put(
                "two_factor_email_code:{$two_factor_token}",
                $email_code,
                now()->addMinutes(self::TWO_FACTOR_EMAIL_CODE_TTL_MINUTES),
            );

            SendEmailJob::dispatch(
                new TwoFactorCodeMail($user, $email_code, self::TWO_FACTOR_EMAIL_CODE_TTL_MINUTES),
                $user->email,
            );

            return response()->json([
                'requires_two_factor' => true,
                'two_factor_token' => $two_factor_token,
            ]);
        }

        return $this->respondWithToken($token, $user);
    }

    /**
     * POST /api/auth/two-factor-challenge
     */
    public function twoFactorChallenge(TwoFactorChallengeRequest $request): JsonResponse
    {
        $cache_key = "two_factor_pending:{$request->two_factor_token}";
        $user_id = Cache::get($cache_key);

        if (! is_int($user_id)) {
            return response()->json([
                'message' => 'The verification session has expired or is invalid. Please sign in again.',
            ], 422);
        }

        $user = User::find($user_id);

        if (! $user || ! $user->hasEnabledTwoFactorAuthentication()) {
            Cache::forget($cache_key);

            return response()->json([
                'message' => 'The verification session has expired or is invalid. Please sign in again.',
            ], 422);
        }

        if (! $user->is_active) {
            Cache::forget($cache_key);

            return response()->json([
                'message' => 'Your account has been disabled. Please contact support if you believe this is a mistake.',
                'code' => 'account_disabled',
            ], 403);
        }

        $code = $request->string('code')->toString();

        if ($recovery_code = $this->validRecoveryCode($user, $request->string('recovery_code')->toString())) {
            $user->replaceRecoveryCode($recovery_code);
        } elseif (! $this->hasValidCode($user, $code) && ! $this->hasValidEmailCode($request->two_factor_token, $code)) {
            return response()->json([
                'message' => 'The provided two factor authentication code was invalid.',
                'errors' => [
                    'code' => ['The provided two factor authentication code was invalid.'],
                ],
            ], 422);
        }

        Cache::forget($cache_key);
        Cache::forget("two_factor_email_code:{$request->two_factor_token}");

        $token = $this->guard()->login($user);

        return $this->respondWithToken($token, $user);
    }

    /**
     * GET /api/auth/me
     */
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->guard()->user();
        $user->load('roles:id,name,display_name');

        return response()->json([
            'user' => new ProfileResource($user),
            'permissions' => $user->getAllPermissions(),
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(): JsonResponse
    {
        try {
            $current_jti = $this->guard()->payload()->get('jti');
            UserSession::where('jti', $current_jti)->update(['revoked_at' => now()]);
        } catch (\Throwable) {
            // No parseable token on the request (e.g. `actingAs()` in tests) — nothing to revoke.
        }

        $this->guard()->logout();

        return response()->json([
            'message' => 'Successfully logged out.',
        ]);
    }

    /**
     * POST /api/auth/refresh
     */
    public function refresh(): JsonResponse
    {
        $previous_jti = $this->guard()->payload()->get('jti');

        $token = $this->guard()->refresh();

        /** @var User $user */
        $user = $this->guard()->user();

        return $this->respondWithToken($token, $user, $previous_jti);
    }

    /**
     * Determine if the given string matches one of the user's unused recovery
     * codes, returning the matched code so it can be replaced.
     */
    private function validRecoveryCode(User $user, string $recovery_code): ?string
    {
        if ($recovery_code === '' || ! $user->two_factor_recovery_codes) {
            return null;
        }

        return collect($user->recoveryCodes())
            ->first(fn ($code) => hash_equals($code, $recovery_code));
    }

    /**
     * Determine if the given code is a valid two factor authentication code
     * for the user.
     */
    private function hasValidCode(User $user, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        return app(TwoFactorAuthenticationProvider::class)->verify(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            $code,
        );
    }

    /**
     * Determine if the given code matches the one time code emailed to the
     * user for this pending login, as an alternative to their authenticator
     * app.
     */
    private function hasValidEmailCode(string $two_factor_token, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        $cached_code = Cache::get("two_factor_email_code:{$two_factor_token}");

        return is_string($cached_code) && hash_equals($cached_code, $code);
    }
}
