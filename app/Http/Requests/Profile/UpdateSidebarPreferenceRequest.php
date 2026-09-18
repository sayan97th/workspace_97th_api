<?php

namespace App\Http\Requests\Profile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSidebarPreferenceRequest extends FormRequest
{
    /** Shared clamp for the sidebar's `width`, mirroring `UpdateBoardColumnRequest`'s own column-width bounds. */
    public const MIN_SIDEBAR_WIDTH = 220;

    public const MAX_SIDEBAR_WIDTH = 480;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'width' => ['required', 'integer', 'min:'.self::MIN_SIDEBAR_WIDTH, 'max:'.self::MAX_SIDEBAR_WIDTH],
        ];
    }
}
