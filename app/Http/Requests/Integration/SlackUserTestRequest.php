<?php

namespace App\Http\Requests\Integration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SlackUserTestRequest extends FormRequest
{
    /**
     * Longest message accepted, well under the 3000 characters Slack allows in one section block,
     * so the sender line and the quote formatting always fit.
     */
    public const MESSAGE_MAX_LENGTH = 1000;

    /**
     * Get the validation rules that apply to the request.
     *
     * `user_id` is the app user who receives the direct message, they must still be active and
     * must have linked their Slack account, which the controller checks. `message` is plain text,
     * it is escaped before it reaches Slack so it can never form links or `<!channel>` broadcasts.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'message' => ['required', 'string', 'max:'.self::MESSAGE_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Choose who should receive the notification.',
            'user_id.exists' => 'That member no longer exists or was deactivated.',
            'message.required' => 'Write the notification message.',
            'message.max' => 'Keep the message under '.self::MESSAGE_MAX_LENGTH.' characters.',
        ];
    }
}
