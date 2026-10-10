<?php

namespace App\Http\Requests\Integration;

use App\Enums\ExternalService;
use App\Services\ExternalAccounts\ExternalAccountService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExternalAccountConnectRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `service` is what the account will be used for (`gmail`, `google_calendar`, `outlook`), it
     * decides the provider and the permissions asked for. `return_path` must be a path inside the
     * app, the same rule the Slack flows use, so the callback can never become an open redirect.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service' => ['required', 'string', Rule::in(ExternalService::values())],
            'return_path' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^/(?!/)[^\s\\\\]*$#'],
            'display' => ['sometimes', 'nullable', 'string', Rule::in([ExternalAccountService::DISPLAY_TAB, ExternalAccountService::DISPLAY_PAGE])],
        ];
    }

    public function service(): ExternalService
    {
        return ExternalService::from($this->validated('service'));
    }

    public function display(): string
    {
        return $this->validated('display') ?? ExternalAccountService::DISPLAY_TAB;
    }
}
