<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkspaceCoverRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The banner is very wide, so anything narrower than 1000px would
            // be upscaled and look blurry.
            'file' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240', 'dimensions:min_width=1000,min_height=200'],
            'cover_position_y' => ['sometimes', 'integer', 'between:0,100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.dimensions' => 'The cover image must be at least 1000px wide and 200px tall.',
            'file.max' => 'The cover image may not be larger than 10 MB.',
        ];
    }
}
