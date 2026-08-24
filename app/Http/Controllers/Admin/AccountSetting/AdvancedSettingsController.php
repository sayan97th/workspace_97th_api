<?php

namespace App\Http\Controllers\Admin\AccountSetting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountSetting\ActivatePanicModeRequest;
use App\Http\Requests\Admin\AccountSetting\UpdateAdvancedSettingsRequest;
use App\Http\Resources\AccountSettingResource;
use App\Models\AccountSetting;
use App\Models\UserSession;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdvancedSettingsController extends Controller
{
    /**
     * PATCH /api/admin/account-settings/advanced
     *
     * Session duration policy only. Panic mode has its own dedicated
     * activate/deactivate endpoints, it is a much higher blast radius action than a
     * settings PATCH.
     */
    public function update(UpdateAdvancedSettingsRequest $request): JsonResponse
    {
        $settings = AccountSetting::current();
        $settings->update($request->validated());

        return response()->json([
            'message' => 'Advanced settings updated successfully.',
            'account_settings' => new AccountSettingResource($settings),
        ]);
    }

    /**
     * POST /api/admin/account-settings/panic-mode
     *
     * Revokes every active session account-wide except the activating admin's own current
     * device, then blocks every non-admin authenticated request (see
     * `EnsurePanicModeAllows`) until deactivated. Requires the admin's own password plus a
     * literal "PANIC" confirmation phrase, this is the single most disruptive action in the
     * whole Administration surface.
     */
    public function activatePanicMode(ActivatePanicModeRequest $request): JsonResponse
    {
        $actor = $request->user();

        if (! Hash::check($request->validated('current_password'), $actor->password)) {
            return response()->json([
                'message' => 'The provided password is incorrect.',
                'errors' => ['current_password' => ['The provided password is incorrect.']],
            ], 422);
        }

        $current_jti = auth('api')->payload()->get('jti');

        $revoked_count = DB::transaction(function () use ($actor, $current_jti) {
            $count = UserSession::query()
                ->whereNull('revoked_at')
                ->where('jti', '!=', $current_jti)
                ->update(['revoked_at' => now()]);

            AccountSetting::current()->update([
                'panic_mode_active' => true,
                'panic_mode_activated_at' => now(),
                'panic_mode_activated_by' => $actor->id,
            ]);

            return $count;
        });

        AuditLogger::log(
            'panic_mode.activated',
            "Activated panic mode, revoking {$revoked_count} active sessions account-wide.",
            $actor,
            ['revoked_count' => $revoked_count]
        );

        return response()->json([
            'message' => 'Panic mode activated. Everyone except you has been signed out and locked out until you deactivate it.',
            'account_settings' => new AccountSettingResource(AccountSetting::current()),
        ]);
    }

    /**
     * DELETE /api/admin/account-settings/panic-mode
     *
     * Does not restore the sessions revoked at activation, those users still need to sign
     * in again, which is the intended behavior.
     */
    public function deactivatePanicMode(): JsonResponse
    {
        $settings = AccountSetting::current();
        $settings->update([
            'panic_mode_active' => false,
            'panic_mode_activated_at' => null,
            'panic_mode_activated_by' => null,
        ]);

        AuditLogger::log('panic_mode.deactivated', 'Deactivated panic mode.', request()->user());

        return response()->json([
            'message' => 'Panic mode deactivated.',
            'account_settings' => new AccountSettingResource($settings),
        ]);
    }
}
