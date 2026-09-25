<?php

namespace App\Http\Requests\Admin\AccountSetting;

use App\Support\AccountPermissions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `permissions` is a partial matrix, `{ role: { permission_key: bool } }`, merged over the
 * stored one, so the page can save a single toggle at a time.
 */
class UpdateAccountPermissionsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['permissions' => ['required', 'array']];

        foreach (AccountPermissions::CONFIGURABLE_ROLES as $role) {
            $rules["permissions.{$role}"] = ['sometimes', 'array'];
            foreach (array_keys(AccountPermissions::DEFINITIONS) as $key) {
                $rules["permissions.{$role}.{$key}"] = ['sometimes', 'boolean'];
            }
        }

        return $rules;
    }
}
