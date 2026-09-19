<?php

namespace App\Http\Requests\Profile;

use App\Enums\EmailDigestFrequency;
use App\Enums\NotificationPreferenceKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preferences' => [
                'sometimes',
                'array',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $allowed_keys = NotificationPreferenceKey::channelKeys();
                    foreach (array_keys($value) as $key) {
                        if (! in_array($key, $allowed_keys, true)) {
                            $fail("The preference key \"{$key}\" is not a valid notification preference.");
                        }
                    }
                },
            ],
            'preferences.*' => ['boolean'],
            'desktop_notifications_enabled' => ['sometimes', 'boolean'],
            'notification_sound_enabled' => ['sometimes', 'boolean'],
            'tab_badge_enabled' => ['sometimes', 'boolean'],
            'quiet_hours_enabled' => ['sometimes', 'boolean'],
            'quiet_hours_start' => ['sometimes', 'date_format:H:i'],
            'quiet_hours_end' => ['sometimes', 'date_format:H:i'],
            'email_digest_frequency' => ['sometimes', Rule::enum(EmailDigestFrequency::class)],
        ];
    }
}
