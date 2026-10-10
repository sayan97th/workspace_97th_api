<?php

namespace App\Http\Requests\Admin\Organization;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationAssetRequest extends FormRequest
{
    /**
     * Logos may be a little larger, the favicon is a tiny square icon. SVG is left out on purpose,
     * an SVG served from the app's own storage can carry scripts.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->route('asset') === 'favicon') {
            return [
                'file' => ['required', 'file', 'mimes:png,ico,webp', 'max:512'],
            ];
        }

        return [
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }
}
