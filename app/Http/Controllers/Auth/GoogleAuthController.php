<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Teams\CreateTeam;
use App\Concerns\IssuesJwtTokens;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

class GoogleAuthController extends Controller
{
    use IssuesJwtTokens;

    public function __construct(private CreateTeam $createTeam)
    {
        //
    }

    public function redirect(): RedirectResponse
    {
        return $this->provider()->stateless()->redirect();
    }

    public function callback(): RedirectResponse
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

                return $user;
            });
        }

        $token = $this->guard()->login($user);

        return redirect("{$frontend_url}/auth/google/callback?".http_build_query([
            'token' => $token,
            'expires_in' => $this->guard()->factory()->getTTL() * 60,
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
