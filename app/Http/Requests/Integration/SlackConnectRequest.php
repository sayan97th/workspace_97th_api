<?php

namespace App\Http\Requests\Integration;

use App\Services\Slack\SlackService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
     * `display` is `tab` when the frontend opened Slack in a new browser tab, the callback then
     * finishes on a page that reports back to the original tab and closes itself.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'return_path' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^/(?!/)[^\s\\\\]*$#'],
            'display' => ['sometimes', 'nullable', 'string', Rule::in([SlackService::DISPLAY_TAB, SlackService::DISPLAY_PAGE])],
        ];
    }

    public function display(): string
    {
        return $this->validated('display') ?? SlackService::DISPLAY_PAGE;
    }
}
