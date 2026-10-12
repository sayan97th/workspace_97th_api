<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBoardDocRequest extends FormRequest
{
    /**
     * The editor JSON document, sent as a string. Null clears the doc.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => ['present', 'nullable', 'string', 'json', 'max:5000000'],
        ];
    }
}
