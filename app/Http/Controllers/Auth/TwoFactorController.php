<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmCurrentPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;

class TwoFactorController extends Controller
{
    public function __construct(
        private EnableTwoFactorAuthentication $enable,
        private ConfirmTwoFactorAuthentication $confirm,
        private DisableTwoFactorAuthentication $disable,
        private GenerateNewRecoveryCodes $generateRecoveryCodes,
    ) {
        //
    }

    /**
     * GET /api/auth/two-factor
     */
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'confirmed_at' => $user->two_factor_confirmed_at,
        ]);
    }

    /**
     * POST /api/auth/two-factor
     */
    public function setup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return response()->json([
                'message' => 'Two-factor authentication is already enabled for this account.',
            ], 422);
        }

        ($this->enable)($user);

        return response()->json([
            'secret' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            'svg' => $user->twoFactorQrCodeSvg(),
            'url' => $user->twoFactorQrCodeUrl(),
        ]);
    }

    /**
     * POST /api/auth/two-factor/confirm
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        /** @var User $user */
        $user = $request->user();

        try {
            ($this->confirm)($user, $request->string('code')->toString());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The verification code is invalid or has expired. Please try again.',
                'errors' => [
                    'code' => ['The verification code is invalid or has expired. Please try again.'],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Two-factor authentication has been enabled successfully.',
            'recovery_codes' => $user->fresh()->recoveryCodes(),
        ]);
    }

    /**
     * DELETE /api/auth/two-factor
     */
    public function disable(ConfirmCurrentPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json([
                'message' => 'Two-factor authentication is not enabled for this account.',
            ], 422);
        }

        ($this->disable)($user);

        return response()->json([
            'message' => 'Two-factor authentication has been disabled successfully.',
        ]);
    }

    /**
     * GET /api/auth/two-factor/recovery-codes
     */
    public function recoveryCodes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json([
                'message' => 'Two-factor authentication is not enabled for this account.',
            ], 422);
        }

        return response()->json([
            'recovery_codes' => $user->recoveryCodes(),
        ]);
    }

    /**
     * POST /api/auth/two-factor/recovery-codes
     */
    public function regenerateRecoveryCodes(ConfirmCurrentPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json([
                'message' => 'Two-factor authentication is not enabled for this account.',
            ], 422);
        }

        ($this->generateRecoveryCodes)($user);

        return response()->json([
            'recovery_codes' => $user->fresh()->recoveryCodes(),
        ]);
    }
}
