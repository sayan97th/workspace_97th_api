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
                $name = $google_user->getName()
                    ?: $google_user->getNickname()
                    ?: Str::before($google_user->getEmail(), '@');

                $user = User::create([
                    'name' => $name,
                    'email' => $google_user->getEmail(),
                    'google_id' => $google_user->getId(),
                    'password' => Str::random(32),
                    'email_verified_at' => now(),
                ]);

                $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

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
