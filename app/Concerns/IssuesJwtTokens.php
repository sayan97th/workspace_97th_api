<?php

namespace App\Concerns;

use App\Http\Resources\ProfileResource;
use App\Models\User;
use App\Models\UserSession;
use App\Support\UserAgentParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

trait IssuesJwtTokens
{
    /**
     * Builds the auth response and records/rotates the {@see UserSession} row for
     * Session history. Pass `$previous_jti` on token refresh (the jti of the token
     * being replaced) so the refresh rotates the existing session row in place
     * instead of spawning a new "device" every ~55 minutes.
     */
    protected function respondWithToken(string $token, User $user, ?string $previous_jti = null): JsonResponse
    {
        $user->load('roles:id,name,display_name');

        $this->recordSession($token, $user, $previous_jti);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $this->guard()->factory()->getTTL() * 60,
            'user' => new ProfileResource($user),
        ]);
    }

    protected function guard(): JWTGuard
    {
        /** @var JWTGuard */
        return Auth::guard('api');
    }

    private function recordSession(string $token, User $user, ?string $previous_jti): void
    {
        $payload = JWTAuth::setToken($token)->getPayload();
        $request = request();

        UserSession::updateOrCreate(
            ['jti' => $previous_jti ?? $payload->get('jti')],
            [
                'user_id' => $user->id,
                'jti' => $payload->get('jti'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_label' => UserAgentParser::parse($request->userAgent()),
                'last_used_at' => now(),
                'expires_at' => Carbon::createFromTimestamp($payload->get('exp')),
                'revoked_at' => null,
            ],
        );
    }
}
