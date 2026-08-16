<?php

namespace App\Http\Requests\Auth;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class JoinWorkspaceByLinkRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * A shareable link isn't addressed to one email like a
     * {@see WorkspaceInvitation}, so the joiner identifies
     * themselves here: an email that already has an account only needs that
     * account's password (to authenticate it); an unknown email creates the
     * account inline, so it needs a full name too.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $email = (string) $this->input('email');

        $base = [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];

        if ($email !== '' && User::where('email', $email)->exists()) {
            return $base;
        }

        return $base + [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ];
    }
}
