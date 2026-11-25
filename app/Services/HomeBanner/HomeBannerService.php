<?php

namespace App\Services\HomeBanner;

use App\Models\HomeBanner;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores, frames and removes Home banner images, both the organization banner and personal ones.
 *
 * Uploads are never served as they arrive. Every image is decoded and written again as WebP in
 * two sizes, which strips camera metadata, drops anything that is not pixels and keeps a 5 MB
 * photo from reaching every visitor of Home:
 *
 * - large, at most {@see self::LARGE_MAX_SIDE} px, sharp on a retina screen at the page's widest;
 * - small, at most {@see self::SMALL_MAX_SIDE} px, picked by the browser on phones and tablets;
 * - a {@see self::PLACEHOLDER_WIDTH} px blurred data URI plus the average color, painted at once
 *   while the real image downloads.
 *
 * Framing (focal point, zoom, text tone, overlay) is stored next to the files, so moving the
 * image around later is a plain update and never re-encodes anything.
 */
class HomeBannerService
{
    private const DIRECTORY = 'home-banners';

    private const LARGE_MAX_SIDE = 2560;

    private const SMALL_MAX_SIDE = 1280;

    private const PLACEHOLDER_WIDTH = 32;

    private const LARGE_QUALITY = 82;

    private const SMALL_QUALITY = 78;

    private const PLACEHOLDER_QUALITY = 40;

    /** Refuses decompression bombs, a 50 megapixel image already needs about 200 MB to decode. */
    private const MAX_SOURCE_PIXELS = 50_000_000;

    /** Share of the width, from the left, that the greeting covers. Used to pick the text color. */
    private const TEXT_AREA_SHARE = 0.6;

    /**
     * Saves a new banner image for the person, or for the organization when `$owner` is null,
     * replacing (and deleting the files of) whatever banner was there before.
     *
     * @param  array<string, mixed>  $framing
     * @param  array{name?: string, size?: int}  $source  the file as picked, before the browser optimized it
     */
    public function store(?User $owner, UploadedFile $file, array $framing, User $actor, array $source = []): HomeBanner
    {
        $decoded = $this->decode((string) $file->getRealPath());

        $large = $this->fitWithin($decoded, self::LARGE_MAX_SIDE);
        $small = $this->fitWithin($decoded, self::SMALL_MAX_SIDE);
        $preview = $this->fitWithin($decoded, self::PLACEHOLDER_WIDTH, only_width: true);

        $large_data = $this->encodeWebp($large, self::LARGE_QUALITY);
        $small_data = $this->encodeWebp($small, self::SMALL_QUALITY);
        $placeholder = 'data:image/webp;base64,'.base64_encode($this->encodeWebp($preview, self::PLACEHOLDER_QUALITY));
        [$dominant_color, $luminance] = $this->measureColors($preview);

        $width = imagesx($large);
        $height = imagesy($large);
        // GD images are freed when they go out of scope (imagedestroy() is deprecated in PHP 8.5).
        unset($decoded, $large, $small, $preview);

        $folder = self::DIRECTORY.'/'.($owner ? 'users/'.$owner->id : 'organization');
        $file_key = Str::uuid()->toString();
        $image_path = "{$folder}/{$file_key}-large.webp";
        $image_small_path = "{$folder}/{$file_key}-small.webp";

        $disk = Storage::disk(config('filesystems.app_disk'));
        $disk->put($image_path, $large_data);
        $disk->put($image_small_path, $small_data);

        $previous = $owner ? HomeBanner::personalBannerFor($owner) : HomeBanner::organizationBanner();
        $old_paths = $previous ? [$previous->image_path, $previous->image_small_path] : [];

        $banner = DB::transaction(function () use (
            $previous, $owner, $framing, $actor, $file, $source, $image_path, $image_small_path,
            $width, $height, $large_data, $placeholder, $dominant_color, $luminance,
        ) {
            $banner = $previous ?? new HomeBanner(['user_id' => $owner?->id]);
            $banner->fill([
                ...HomeBanner::DEFAULT_FRAMING,
                ...$this->normalizeFraming($framing),
                'image_path' => $image_path,
                'image_small_path' => $image_small_path,
                'width' => $width,
                'height' => $height,
                'stored_size' => strlen($large_data),
                'original_name' => Str::limit($source['name'] ?? $file->getClientOriginalName(), 250, ''),
                'original_size' => (int) ($source['size'] ?? $file->getSize()),
                'placeholder' => $placeholder,
                'dominant_color' => $dominant_color,
                'luminance' => $luminance,
                'updated_by' => $actor->id,
            ]);
            $banner->save();

            return $banner;
        });

        $this->deleteFiles($old_paths);

        return $banner->load('editor');
    }

    /**
     * Saves a new framing for the image already stored, no upload involved.
     *
     * @param  array<string, mixed>  $framing
     */
    public function reframe(HomeBanner $banner, array $framing, User $actor): HomeBanner
    {
        $banner->fill([...$this->normalizeFraming($framing), 'updated_by' => $actor->id]);
        $banner->save();

        return $banner->load('editor');
    }

    /**
     * Removes the banner and its files, Home falls back to the next banner in line.
     */
    public function destroy(HomeBanner $banner): void
    {
        $paths = [$banner->image_path, $banner->image_small_path];
        $banner->delete();
        $this->deleteFiles($paths);
    }

    /**
     * Keeps only the framing keys that were sent, clamped to their allowed ranges.
     *
     * @param  array<string, mixed>  $framing
     * @return array<string, float|int|string>
     */
    private function normalizeFraming(array $framing): array
    {
        $normalized = [];

        foreach (['focal_x', 'focal_y'] as $axis) {
            if (isset($framing[$axis])) {
                $normalized[$axis] = round(max(0, min(100, (float) $framing[$axis])), 2);
            }
        }
        if (isset($framing['zoom'])) {
            $normalized['zoom'] = round(max(HomeBanner::MIN_ZOOM, min(HomeBanner::MAX_ZOOM, (float) $framing['zoom'])), 2);
        }
        if (isset($framing['text_tone']) && in_array($framing['text_tone'], HomeBanner::TEXT_TONES, true)) {
            $normalized['text_tone'] = $framing['text_tone'];
        }
        if (array_key_exists('overlay_color', $framing)) {
            $color = is_string($framing['overlay_color']) ? strtolower($framing['overlay_color']) : null;
            $normalized['overlay_color'] = $color && preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : null;
        }
        if (isset($framing['overlay_opacity'])) {
            $normalized['overlay_opacity'] = max(0, min(HomeBanner::MAX_OVERLAY_OPACITY, (int) $framing['overlay_opacity']));
        }

        return $normalized;
    }

    /**
     * Reads any raster format GD understands (JPEG, PNG, WebP, GIF, BMP, AVIF) into a true color
     * image that keeps its transparency.
     */
    private function decode(string $source_path): GdImage
    {
        $size = @getimagesize($source_path);
        if ($size === false) {
            throw ValidationException::withMessages(['file' => 'This file is not an image we can read. Try a PNG, JPG or WebP.']);
        }
        if ($size[0] * $size[1] > self::MAX_SOURCE_PIXELS) {
            throw ValidationException::withMessages(['file' => 'This image is too large to process. Keep it under 50 megapixels.']);
        }

        $source_data = file_get_contents($source_path);
        $image = $source_data !== false ? @imagecreatefromstring($source_data) : false;
        if ($image === false) {
            throw ValidationException::withMessages(['file' => 'This image could not be read. Try saving it again as PNG, JPG or WebP.']);
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * Scales the image down so its longest side (or only its width) fits `$max_side`. Never
     * upscales, a smaller image is returned as is.
     */
    private function fitWithin(GdImage $image, int $max_side, bool $only_width = false): GdImage
    {
        $source_width = imagesx($image);
        $source_height = imagesy($image);
        $limit = $only_width ? $source_width : max($source_width, $source_height);

        if ($limit <= $max_side) {
            return $image;
        }

        $ratio = $max_side / $limit;
        $target_width = max(1, (int) round($source_width * $ratio));
        $target_height = max(1, (int) round($source_height * $ratio));

        $resized = imagecreatetruecolor($target_width, $target_height);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $target_width, $target_height, $source_width, $source_height);

        return $resized;
    }

    private function encodeWebp(GdImage $image, int $quality): string
    {
        ob_start();
        imagewebp($image, quality: $quality);

        return (string) ob_get_clean();
    }

    /**
     * The average color of the whole image, and the average brightness of the area behind the
     * greeting. Transparent pixels count as white, the color of the card under them.
     *
     * @return array{0: string, 1: float}
     */
    private function measureColors(GdImage $preview): array
    {
        $width = imagesx($preview);
        $height = imagesy($preview);
        $text_area_width = max(1, (int) ceil($width * self::TEXT_AREA_SHARE));

        $totals = ['r' => 0, 'g' => 0, 'b' => 0];
        $luminance_total = 0.0;
        $text_area_pixels = 0;

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $color = imagecolorat($preview, $x, $y);
                $opacity = 1 - ((($color >> 24) & 0x7F) / 127);
                $red = (($color >> 16) & 0xFF) * $opacity + 255 * (1 - $opacity);
                $green = (($color >> 8) & 0xFF) * $opacity + 255 * (1 - $opacity);
                $blue = ($color & 0xFF) * $opacity + 255 * (1 - $opacity);

                $totals['r'] += $red;
                $totals['g'] += $green;
                $totals['b'] += $blue;

                if ($x < $text_area_width) {
                    $luminance_total += (0.2126 * $red + 0.7152 * $green + 0.0722 * $blue) / 255;
                    $text_area_pixels++;
                }
            }
        }

        $pixel_count = max(1, $width * $height);
        $dominant_color = sprintf(
            '#%02x%02x%02x',
            (int) round($totals['r'] / $pixel_count),
            (int) round($totals['g'] / $pixel_count),
            (int) round($totals['b'] / $pixel_count),
        );

        return [$dominant_color, round($luminance_total / max(1, $text_area_pixels), 3)];
    }

    /**
     * @param  array<int, string|null>  $paths
     */
    private function deleteFiles(array $paths): void
    {
        $disk = Storage::disk(config('filesystems.app_disk'));

        foreach (array_filter($paths) as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }
}
