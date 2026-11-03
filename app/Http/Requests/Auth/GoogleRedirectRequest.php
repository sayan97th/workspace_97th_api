<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GoogleRedirectRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `callback_url` is the frontend path to land on after signing in, only
     * relative paths are accepted so it can never point at another site.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'remember' => ['sometimes', 'boolean'],
            'callback_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'starts_with:/', 'not_regex:/^\/[\/\\\\]/'],
        ];
    }
}
