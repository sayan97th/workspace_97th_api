<?php

namespace App\Http\Requests\Integration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SlackChannelIndexRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `refresh` skips the short lived channel cache, so a channel created in Slack, or a
     * private channel the app was just invited to, shows up without waiting for it to expire.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'refresh' => ['sometimes', 'boolean'],
        ];
    }

    public function wantsFreshChannels(): bool
    {
        return $this->boolean('refresh');
    }
}
