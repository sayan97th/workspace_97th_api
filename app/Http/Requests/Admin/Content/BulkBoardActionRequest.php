<?php

namespace App\Http\Requests\Admin\Content;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BulkBoardActionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'board_ids' => ['required', 'array', 'min:1', 'max:500'],
            'board_ids.*' => ['integer', 'distinct'],
        ];
    }
}
