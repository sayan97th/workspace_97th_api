<?php

namespace App\Http\Requests\Integration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SlackAppSelectionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `app` is `environment`, the app set in the API .env file, or the id of a saved app.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'app' => ['required', 'string', 'max:20', 'regex:/^(environment|\d+)$/'],
        ];
    }
}
