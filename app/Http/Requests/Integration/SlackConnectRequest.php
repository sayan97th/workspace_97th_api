<?php

namespace App\Http\Requests\Integration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SlackConnectRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `return_path` is where the callback sends the browser once Slack is done, for example
     * the board the person clicked "Integrate" on. It must be a path inside the app: a single
     * leading slash, no scheme, no protocol relative `//` and no backslashes, so the callback
     * can never be turned into an open redirect.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'return_path' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^/(?!/)[^\s\\\\]*$#'],
        ];
    }
}
