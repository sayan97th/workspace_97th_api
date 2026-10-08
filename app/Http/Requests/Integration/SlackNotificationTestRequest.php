<?php

namespace App\Http\Requests\Integration;

use App\Enums\SlackNotificationTest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SlackNotificationTestRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Which fields are required depends on the test in the URL: `user_id` is the member who
     * receives the message (they must be active, whether they linked Slack is checked by the
     * runner), `channel_id` is a Slack conversation id from the channels endpoint, `slack_user_id`
     * is any member of the Slack workspace (`U...` or `W...` for Enterprise Grid) and `message`
     * is plain text, escaped before it reaches Slack.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $test = $this->notificationTest();

        return [
            'user_id' => [$test->needsRecipient() ? 'required' : 'nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'channel_id' => [$test->needsChannel() ? 'required' : 'nullable', 'string', 'max:32', 'regex:/^[CG][A-Z0-9]+$/'],
            'slack_user_id' => [$test->needsSlackMember() ? 'required' : 'nullable', 'string', 'max:32', 'regex:/^[UW][A-Z0-9]+$/'],
            'message' => [$test->needsSlackMember() ? 'required' : 'nullable', 'string', 'max:'.SlackUserTestRequest::MESSAGE_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Choose who should receive the test.',
            'user_id.exists' => 'That member no longer exists or was deactivated.',
            'channel_id.required' => 'Choose a channel for this test.',
            'channel_id.regex' => 'Choose a channel from the list.',
            'slack_user_id.required' => 'Choose the Slack member who should receive the message.',
            'slack_user_id.regex' => 'Choose a Slack member from the list.',
            'message.required' => 'Write the message.',
            'message.max' => 'Keep the message under '.SlackUserTestRequest::MESSAGE_MAX_LENGTH.' characters.',
        ];
    }

    public function notificationTest(): SlackNotificationTest
    {
        $test = $this->route('test');

        return $test instanceof SlackNotificationTest ? $test : SlackNotificationTest::from((string) $test);
    }
}
