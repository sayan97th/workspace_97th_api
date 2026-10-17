<?php

namespace App\Http\Requests\Integration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SlackWorkspaceSettingsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `notify_invitations` sends workspace and board invitations as a Slack direct message too.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notify_invitations' => ['required', 'boolean'],
        ];
    }
}
