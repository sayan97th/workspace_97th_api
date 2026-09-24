<?php

namespace App\Http\Requests\Integration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SlackChannelTestRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `channel_id` is a Slack conversation id as returned by the channels endpoint, for
     * example `C0123ABCD` for a public channel or `G0123ABCD` for an older private one.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'channel_id' => ['required', 'string', 'max:32', 'regex:/^[CG][A-Z0-9]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'channel_id.regex' => 'Choose a channel from the list.',
        ];
    }
}
