<?php

namespace App\Http\Requests\Admin\AccountSetting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountPreferencesRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'weekend_start' => ['sometimes', 'in:fri_sat,sat_sun'],
            'show_weekends' => ['sometimes', 'boolean'],
            'home_page' => ['sometimes', 'in:default,dashboard'],
        ];
    }
}
