<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBoardInvitationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'emails' => ['required', 'array', 'min:1', 'max:25'],
            'emails.*' => ['required', 'string', 'email', 'max:255', 'distinct:ignore_case'],
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
