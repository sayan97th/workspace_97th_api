<?php

namespace App\Concerns;

use App\Jobs\SendEmailJob;
use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

trait StartsTwoFactorChallenges
{
    /**
     * How long an emailed two factor code, and the matching pending login
     * token, stay valid before the user has to sign in again.
     */
    protected const TWO_FACTOR_EMAIL_CODE_TTL_MINUTES = 10;

    /**
     * Parks a login that still has to pass the two factor challenge, emails the
     * one time code and returns the pending token the client sends back to
     * `POST /api/auth/two-factor-challenge` together with the code.
     *
     * The "Keep me logged in" choice waits in the cache until the challenge is passed.
     */
    protected function startTwoFactorChallenge(User $user, bool $remember): string
    {
        $two_factor_token = Str::uuid()->toString();
        $email_code = (string) random_int(100000, 999999);
        $expires_at = now()->addMinutes(self::TWO_FACTOR_EMAIL_CODE_TTL_MINUTES);

        Cache::put("two_factor_pending:{$two_factor_token}", $user->id, $expires_at);
        Cache::put("two_factor_email_code:{$two_factor_token}", $email_code, $expires_at);
        Cache::put("two_factor_remember:{$two_factor_token}", $remember, $expires_at);

        SendEmailJob::dispatch(
            new TwoFactorCodeMail($user, $email_code, self::TWO_FACTOR_EMAIL_CODE_TTL_MINUTES),
            $user->email,
        );

        return $two_factor_token;
    }
}
