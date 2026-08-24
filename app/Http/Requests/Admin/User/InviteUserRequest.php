<?php

namespace App\Http\Requests\Admin\User;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InviteUserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', 'in:super_admin,admin,staff,client'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Someone with this email already has an account.',
        ];
    }
}
