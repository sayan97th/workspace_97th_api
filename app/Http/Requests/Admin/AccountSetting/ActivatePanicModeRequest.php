<?php

namespace App\Http\Requests\Admin\AccountSetting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ActivatePanicModeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'confirmation_phrase' => ['required', 'string', 'in:PANIC'],
        ];
    }
}
