<?php

namespace App\Http\Requests\Integration;

use App\Services\Slack\SlackAppCredentials;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SlackAppSelectionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `environment` is the production 97th Floor app from the API environment, `database` the
     * testing app saved from Administration.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', 'string', Rule::in(SlackAppCredentials::SELECTABLE_SOURCES)],
        ];
    }
}
