<?php

namespace App\Http\Requests\Admin\Department;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssignDepartmentOwnersRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:50'],
            'user_ids.*' => [
                'integer',
                'distinct',
                'exists:users,id',
                // Owners get access to Administration, so they must already be staff-tier.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $user = User::find($value);

                    if ($user && ! $user->hasRole(['super_admin', 'admin', 'staff'])) {
                        $fail('Only staff members can be department owners.');
                    }
                },
            ],
        ];
    }
}
