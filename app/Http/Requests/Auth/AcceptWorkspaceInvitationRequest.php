<?php

namespace App\Http\Requests\Auth;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AcceptWorkspaceInvitationRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * Accepting an invitation for an email that already has an account only
     * needs that account's password (to authenticate it); an unknown email
     * creates the account inline, so it needs a full name too.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $invitation = $this->route('invitation');

        abort_if(! $invitation instanceof WorkspaceInvitation, 404);

        if (User::where('email', $invitation->email)->exists()) {
            return [
                'password' => ['required', 'string'],
            ];
        }

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ];
    }
}
