<?php

namespace App\Http\Requests\Board;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Renames one entry of a Files cell (the display name of an upload, or the
 * display text of a link). The stored file itself keeps its storage name.
 */
class RenameBoardItemCellFileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('file_name'))) {
            $this->merge(['file_name' => trim($this->input('file_name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Slashes and control characters would turn the name into a path once downloaded.
            'file_name' => ['required', 'string', 'max:255', 'not_regex:/[\\\\\/\x00-\x1F]/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file_name.required' => 'The file name cannot be empty.',
            'file_name.not_regex' => 'The file name cannot contain slashes.',
        ];
    }
}
