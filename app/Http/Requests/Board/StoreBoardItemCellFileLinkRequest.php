<?php

namespace App\Http\Requests\Board;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Files cell's "From Link" entry: any external URL (a PDF, a Figma or Miro
 * board, a Google Doc, ...) plus an optional label shown in its place.
 */
class StoreBoardItemCellFileLinkRequest extends FormRequest
{
    /**
     * Adds a scheme to a bare "example.com/file.pdf" so it validates and
     * opens as an absolute URL, the same way a browser address bar would.
     */
    protected function prepareForValidation(): void
    {
        $url = trim((string) $this->input('url', ''));

        if ($url !== '' && ! preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url)) {
            $url = 'https://'.$url;
        }

        $this->merge([
            'url' => $url,
            'text' => trim((string) $this->input('text', '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'text' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'Paste a link to add.',
            'url.url' => 'Enter a valid http or https link.',
        ];
    }
}
