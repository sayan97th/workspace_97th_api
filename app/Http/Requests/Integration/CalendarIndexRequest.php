<?php

namespace App\Http\Requests\Integration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CalendarIndexRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `refresh=1` reads the calendars from Google again instead of the short lived cache.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['refresh' => ['sometimes', 'boolean']];
    }

    public function wantsFresh(): bool
    {
        return $this->boolean('refresh');
    }
}
