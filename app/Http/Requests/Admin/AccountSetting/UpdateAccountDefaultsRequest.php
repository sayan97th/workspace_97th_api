<?php

namespace App\Http\Requests\Admin\AccountSetting;

use App\Enums\DateFormat;
use App\Enums\FirstDayOfWeek;
use App\Enums\TimeFormat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountDefaultsRequest extends FormRequest
{
    /** Mirrors `UpdateLocalePreferencesRequest::SUPPORTED_LANGUAGES`, the languages a user can pick. */
    public const SUPPORTED_LANGUAGES = ['en', 'es', 'pt', 'fr', 'de', 'it', 'ja', 'ko', 'zh'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'default_timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'default_language' => ['sometimes', 'required', 'string', Rule::in(self::SUPPORTED_LANGUAGES)],
            'default_date_format' => ['sometimes', 'required', 'string', Rule::in(DateFormat::values())],
            'default_time_format' => ['sometimes', 'required', 'string', Rule::in(TimeFormat::values())],
            'default_first_day_of_week' => ['sometimes', 'required', 'string', Rule::in(FirstDayOfWeek::values())],
        ];
    }
}
