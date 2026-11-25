<?php

namespace App\Http\Requests\HomeBanner;

use App\Models\HomeBanner;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new Home banner image, personal or for the organization, with its first framing.
 *
 * The app shrinks and converts the picture in the browser before sending it (SVG included, it
 * arrives already turned into pixels), so this mostly guards direct API calls. SVG files are
 * refused here on purpose, an SVG served from the app's own storage can carry scripts.
 */
class StoreHomeBannerRequest extends FormRequest
{
    /** Matches the editor's limit for an untouched original. */
    public const MAX_KILOBYTES = 20480;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file', 'mimes:png,jpg,jpeg,webp,gif,bmp,avif', 'max:'.self::MAX_KILOBYTES,
                'dimensions:min_width=640,min_height=160',
            ],
            ...UpdateHomeBannerRequest::framingRules(is_required: false),
            // The file the person picked before the browser optimized it, shown as "5.2 MB optimized to 400 KB".
            'source_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source_size' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Upload a PNG, JPG, WebP, GIF, BMP or AVIF image.',
            'file.max' => 'The banner image may not be larger than 20 MB.',
            'file.dimensions' => 'The banner image must be at least 640px wide and 160px tall. 2400 by 600 px looks best.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function framing(): array
    {
        return $this->safe()->only(array_keys(HomeBanner::DEFAULT_FRAMING));
    }

    /**
     * Name and size of the original file, falling back to the uploaded one.
     *
     * @return array{name: string, size: int}
     */
    public function sourceDetails(): array
    {
        $file = $this->file('file');

        return [
            'name' => (string) ($this->validated('source_name') ?: $file->getClientOriginalName()),
            'size' => (int) ($this->validated('source_size') ?: $file->getSize()),
        ];
    }
}
