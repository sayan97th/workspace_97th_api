<?php

namespace App\Http\Controllers\Admin\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\UpdateUserProfileFieldValuesRequest;
use App\Http\Resources\UserWithRolesResource;
use App\Models\User;
use App\Models\UserProfileFieldValue;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/admin/users/{user}/profile-fields
 *
 * Sets (or clears, with null) one user's values for the account's custom profile fields.
 * Only the fields present in `values` change; the rest are left as they are.
 */
class UserProfileFieldValueController extends Controller
{
    public function __invoke(UpdateUserProfileFieldValuesRequest $request, User $user): JsonResponse
    {
        $values = $request->validated('values');

        DB::transaction(function () use ($user, $values) {
            foreach ($values as $field_id => $value) {
                $value = $value === null ? null : trim((string) $value);

                if ($value === null || $value === '') {
                    UserProfileFieldValue::query()->where('user_id', $user->id)->where('field_id', (int) $field_id)->delete();

                    continue;
                }

                UserProfileFieldValue::query()->updateOrCreate(
                    ['user_id' => $user->id, 'field_id' => (int) $field_id],
                    ['value' => $value],
                );
            }
        });

        AuditLogger::log('user.profile_fields_updated', "Updated {$user->full_name}'s profile fields.", $request->user(), [
            'target_user_id' => $user->id,
            'field_ids' => array_map('intval', array_keys($values)),
        ]);

        return response()->json([
            'message' => 'Profile fields updated.',
            'user' => new UserWithRolesResource($user->fresh(['roles:id,name,display_name', 'department:id,name', 'profileFieldValues'])),
        ]);
    }
}
