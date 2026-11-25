<?php

namespace App\Http\Resources;

use App\Models\HomeBanner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Home banner image with everything the hero needs to paint it: both renditions, the blurred
 * placeholder, its colors and the saved framing.
 *
 * @mixin HomeBanner
 */
class HomeBannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->isOrganizationBanner() ? 'organization' : 'personal',
            'image_url' => $this->image_url,
            'image_small_url' => $this->image_small_url,
            'width' => $this->width,
            'height' => $this->height,
            'stored_size' => $this->stored_size,
            'original_name' => $this->original_name,
            'original_size' => $this->original_size,
            'placeholder' => $this->placeholder,
            'dominant_color' => $this->dominant_color,
            'luminance' => $this->luminance,
            'focal_x' => $this->focal_x,
            'focal_y' => $this->focal_y,
            'zoom' => $this->zoom,
            'text_tone' => $this->text_tone,
            'overlay_opacity' => $this->overlay_opacity,
            'overlay_color' => $this->overlay_color,
            'updated_by' => $this->whenLoaded('editor', fn () => $this->editor ? [
                'id' => $this->editor->id,
                'full_name' => $this->editor->full_name,
            ] : null),
            'updated_at' => $this->updated_at,
        ];
    }
}
