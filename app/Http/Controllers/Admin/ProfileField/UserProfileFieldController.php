<?php

namespace App\Http\Controllers\Admin\ProfileField;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProfileField\ReorderUserProfileFieldsRequest;
use App\Http\Requests\Admin\ProfileField\StoreUserProfileFieldRequest;
use App\Http\Requests\Admin\ProfileField\UpdateUserProfileFieldRequest;
use App\Http\Resources\UserProfileFieldResource;
use App\Models\UserProfileField;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Administration > Customization > Profile fields: the account's custom user profile
 * fields. Readable by every staff tier user (the Users table renders them as columns),
 * managed by admins only.
 */
class UserProfileFieldController extends Controller
{
    /**
     * GET /api/admin/profile-fields
     */
    public function index(): JsonResponse
    {
        $fields = UserProfileField::query()
            ->withCount(['values' => fn ($query) => $query->whereNotNull('value')->where('value', '!=', '')])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => UserProfileFieldResource::collection($fields)]);
    }

    /**
     * POST /api/admin/profile-fields
     */
    public function store(StoreUserProfileFieldRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $field = UserProfileField::create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'options' => $validated['type'] === UserProfileField::TYPE_DROPDOWN ? array_values($validated['options'] ?? []) : null,
            'position' => (int) UserProfileField::query()->max('position') + 1,
        ]);

        AuditLogger::log('profile_field.created', "Created the \"{$field->name}\" profile field.", $request->user(), ['field_id' => $field->id, 'type' => $field->type]);

        return response()->json([
            'message' => 'Profile field created.',
            'field' => new UserProfileFieldResource($field),
        ], 201);
    }

    /**
     * PATCH /api/admin/profile-fields/{field}
     *
     * Removing a dropdown option also clears it from every user who had it selected, so no
     * value is left pointing at a choice that no longer exists.
     */
    public function update(UpdateUserProfileFieldRequest $request, UserProfileField $field): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($field, $validated) {
            if (array_key_exists('name', $validated)) {
                $field->name = $validated['name'];
            }

            if (array_key_exists('options', $validated) && $field->type === UserProfileField::TYPE_DROPDOWN) {
                $previous_ids = $field->optionIds();
                $field->options = array_values($validated['options'] ?? []);
                $removed_ids = array_values(array_diff($previous_ids, $field->optionIds()));

                if ($removed_ids !== []) {
                    $field->values()->whereIn('value', $removed_ids)->delete();
                }
            }

            $field->save();
        });

        AuditLogger::log('profile_field.updated', "Updated the \"{$field->name}\" profile field.", $request->user(), ['field_id' => $field->id]);

        return response()->json([
            'message' => 'Profile field updated.',
            'field' => new UserProfileFieldResource($field->fresh()),
        ]);
    }

    /**
     * DELETE /api/admin/profile-fields/{field}
     */
    public function destroy(Request $request, UserProfileField $field): JsonResponse
    {
        $name = $field->name;
        $field->delete();

        AuditLogger::log('profile_field.deleted', "Deleted the \"{$name}\" profile field and its values.", $request->user(), ['field_id' => $field->id]);

        return response()->json(['message' => 'Profile field deleted.']);
    }

    /**
     * PUT /api/admin/profile-fields/order
     */
    public function reorder(ReorderUserProfileFieldsRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request) {
            foreach ($request->validated('field_ids') as $position => $field_id) {
                UserProfileField::query()->whereKey($field_id)->update(['position' => $position]);
            }
        });

        return $this->index();
    }
}
