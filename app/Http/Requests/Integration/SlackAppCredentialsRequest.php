<?php

namespace App\Http\Requests\Integration;

use App\Services\Slack\SlackAppCredentials;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SlackAppCredentialsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The values come from the Slack app's "Basic Information" page. Both secrets may be left
     * blank to keep the saved ones, see {@see SlackAppCredentials::save()}.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'string', 'max:64', 'regex:/^\d+\.\d+$/'],
            'client_secret' => ['sometimes', 'nullable', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
            'signing_secret' => ['sometimes', 'nullable', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
            'redirect_uri' => ['sometimes', 'nullable', 'string', 'max:255', 'url:https,http', 'ends_with:/api/integrations/slack/callback'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.regex' => 'The client ID is two numbers joined by a dot, copy it from the Slack app\'s Basic Information page.',
            'client_secret.regex' => 'The client secret only contains letters and numbers.',
            'signing_secret.regex' => 'The signing secret only contains letters and numbers.',
            'redirect_uri.ends_with' => 'The redirect URL must end with /api/integrations/slack/callback.',
        ];
    }
}
