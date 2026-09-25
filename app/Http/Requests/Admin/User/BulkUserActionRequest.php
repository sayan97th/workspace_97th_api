<?php

namespace App\Http\Requests\Admin\User;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUserActionRequest extends FormRequest
{
    public const ACTIONS = ['set_department', 'set_role', 'deactivate', 'reactivate'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'distinct'],
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'role' => ['required_if:action,set_role', 'nullable', 'string', Rule::in(['super_admin', 'admin', 'staff', 'client'])],
        ];
    }
}
