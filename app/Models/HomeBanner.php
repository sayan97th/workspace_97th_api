<?php

namespace App\Models;

use App\Services\HomeBanner\HomeBannerService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A custom background image for the welcome banner at the top of Home, see the
 * `create_home_banners_table` migration. A null `user_id` marks the organization banner, every
 * other row belongs to one person. Files are written by {@see HomeBannerService}.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $image_path
 * @property string $image_small_path
 * @property int $width
 * @property int $height
 * @property int $stored_size
 * @property string|null $original_name
 * @property int $original_size
 * @property string|null $placeholder
 * @property string|null $dominant_color
 * @property float $luminance
 * @property float $focal_x
 * @property float $focal_y
 * @property float $zoom
 * @property string $text_tone
 * @property int $overlay_opacity
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $image_url
 * @property-read string $image_small_url
 * @property-read User|null $user
 * @property-read User|null $editor
 */
#[Fillable([
    'user_id', 'image_path', 'image_small_path', 'width', 'height', 'stored_size',
    'original_name', 'original_size', 'placeholder', 'dominant_color', 'luminance',
    'focal_x', 'focal_y', 'zoom', 'text_tone', 'overlay_opacity', 'updated_by',
])]
class HomeBanner extends Model
{
    /** `auto` picks light or dark text from the image's {@see self::$luminance}. */
    public const TEXT_TONES = ['auto', 'light', 'dark'];

    public const MIN_ZOOM = 1;

    public const MAX_ZOOM = 3;

    /** Past this the image is barely visible, so the shade stops there. */
    public const MAX_OVERLAY_OPACITY = 80;

    public const DEFAULT_FRAMING = [
        'focal_x' => 50,
        'focal_y' => 50,
        'zoom' => 1,
        'text_tone' => 'auto',
        'overlay_opacity' => 35,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'stored_size' => 'integer',
            'original_size' => 'integer',
            'luminance' => 'float',
            'focal_x' => 'float',
            'focal_y' => 'float',
            'zoom' => 'float',
            'overlay_opacity' => 'integer',
        ];
    }

    /**
     * The one banner an admin set for everyone in the organization, if any.
     */
    public static function organizationBanner(): ?self
    {
        return self::query()->whereNull('user_id')->oldest('id')->first();
    }

    /**
     * The person's own banner, if they uploaded one.
     */
    public static function personalBannerFor(User $user): ?self
    {
        return self::query()->where('user_id', $user->id)->first();
    }

    public function isOrganizationBanner(): bool
    {
        return $this->user_id === null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(get: fn () => Storage::disk(config('filesystems.app_disk'))->url($this->image_path));
    }

    protected function imageSmallUrl(): Attribute
    {
        return Attribute::make(get: fn () => Storage::disk(config('filesystems.app_disk'))->url($this->image_small_path));
    }
}
