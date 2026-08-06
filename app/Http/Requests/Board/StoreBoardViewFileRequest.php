<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBoardViewFileRequest extends FormRequest
{
    /**
     * A Files Gallery accepts any file type (unlike comment attachments,
     * which are narrowed to document/image mimes) — only a per-file size cap
     * applies.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:51200'],
        ];
    }
}
