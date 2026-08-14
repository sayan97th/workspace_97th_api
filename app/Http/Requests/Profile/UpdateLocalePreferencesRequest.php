<?php

namespace App\Http\Requests\Profile;

use App\Enums\DateFormat;
use App\Enums\FirstDayOfWeek;
use App\Enums\TimeFormat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocalePreferencesRequest extends FormRequest
{
    /**
     * The languages the product's UI supports. Storing the preference does not currently
     * translate any UI copy — the app has no i18n system — this just persists the choice.
     */
    private const SUPPORTED_LANGUAGES = ['en', 'es', 'pt', 'fr', 'de', 'it', 'ja', 'ko', 'zh'];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'language' => ['sometimes', 'nullable', 'string', Rule::in(self::SUPPORTED_LANGUAGES)],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'time_format' => ['sometimes', 'nullable', 'string', Rule::in(TimeFormat::values())],
            'date_format' => ['sometimes', 'nullable', 'string', Rule::in(DateFormat::values())],
            'first_day_of_week' => ['sometimes', 'nullable', 'string', Rule::in(FirstDayOfWeek::values())],
        ];
    }
}
