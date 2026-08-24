<?php

namespace App\Http\Requests\Admin\AccountSetting;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmailHeaderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }
}
