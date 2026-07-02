<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Teams\CreateTeam;
use App\Concerns\IssuesJwtTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class AuthController extends Controller
{
    use IssuesJwtTokens;

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

            return $user;
        });

        $token = $this->guard()->login($user);

        return $this->respondWithToken($token, $user);
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

            Cache::put("two_factor_pending:{$two_factor_token}", $user->id, now()->addMinutes(10));

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

        if ($recovery_code = $this->validRecoveryCode($user, $request->string('recovery_code')->toString())) {
            $user->replaceRecoveryCode($recovery_code);
        } elseif (! $this->hasValidCode($user, $request->string('code')->toString())) {
            return response()->json([
                'message' => 'The provided two factor authentication code was invalid.',
                'errors' => [
                    'code' => ['The provided two factor authentication code was invalid.'],
                ],
            ], 422);
        }

        Cache::forget($cache_key);

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
            'user' => $this->formatUser($user),
            'permissions' => $user->getAllPermissions(),
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(): JsonResponse
    {
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
        $token = $this->guard()->refresh();

        /** @var User $user */
        $user = $this->guard()->user();

        return $this->respondWithToken($token, $user);
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
}
