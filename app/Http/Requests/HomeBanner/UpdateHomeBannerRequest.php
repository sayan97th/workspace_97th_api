<?php

namespace App\Http\Requests\HomeBanner;

use App\Models\HomeBanner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * New framing for a Home banner that is already uploaded: where it is centered, how far it is
 * zoomed in, the greeting color and how strong the shade over the image is.
 */
class UpdateHomeBannerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::framingRules(is_required: false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function framingRules(bool $is_required): array
    {
        $presence = $is_required ? 'required' : 'sometimes';

        return [
            'focal_x' => [$presence, 'numeric', 'between:0,100'],
            'focal_y' => [$presence, 'numeric', 'between:0,100'],
            'zoom' => [$presence, 'numeric', 'between:'.HomeBanner::MIN_ZOOM.','.HomeBanner::MAX_ZOOM],
            'text_tone' => [$presence, 'string', Rule::in(HomeBanner::TEXT_TONES)],
            'overlay_opacity' => [$presence, 'integer', 'between:0,'.HomeBanner::MAX_OVERLAY_OPACITY],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function framing(): array
    {
        return $this->safe()->only(array_keys(HomeBanner::DEFAULT_FRAMING));
    }
}
