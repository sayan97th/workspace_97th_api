<?php

namespace App\Http\Requests\Integration;

use App\Enums\ExternalService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExternalAccountIndexRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `service` narrows the list to the accounts that can be used for it, every account otherwise.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service' => ['sometimes', 'nullable', 'string', Rule::in(ExternalService::values())],
        ];
    }

    public function service(): ?ExternalService
    {
        return ExternalService::tryFrom((string) $this->validated('service'));
    }
}
