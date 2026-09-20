<?php

namespace App\Http\Requests\Admin\User;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'email' => [
                'required',
                'email',
                'max:255',
                // A deleted account keeps its email, so it is reported separately below
                // instead of as "already has an account".
                Rule::unique(User::class)->whereNull('deleted_at'),
                fn (string $attribute, mixed $value, \Closure $fail) => User::onlyTrashed()->where('email', $value)->exists()
                    ? $fail('This email belongs to a deleted account. Restore that account from Administration instead.')
                    : null,
            ],
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
