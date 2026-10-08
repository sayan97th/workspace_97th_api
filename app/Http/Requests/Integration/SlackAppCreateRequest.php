<?php

namespace App\Http\Requests\Integration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SlackAppCreateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The app configuration token from api.slack.com/apps > "Your App Configuration Tokens".
     * Its access token starts with `xoxe.xoxp-`, the refresh token (`xoxe-`) cannot create apps.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'configuration_token' => ['required', 'string', 'max:512', 'starts_with:xoxe.xoxp-', 'regex:/^[A-Za-z0-9.\-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'configuration_token.starts_with' => 'Paste the access token, the one that starts with xoxe.xoxp-, not the refresh token.',
            'configuration_token.regex' => 'That does not look like a Slack configuration token.',
        ];
    }
}
