<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBoardItemCoverRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cover' => ['required', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:8192'],
        ];
    }
}
