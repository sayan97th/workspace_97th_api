<?php

namespace App\Services\Board;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Light copies of an image uploaded to a Files cell, so the board never has
 * to download the original just to draw it:
 *
 * - `thumb`: at most 480px on its longest side, WebP. Used by the cell and
 *   menu thumbnails, the hover preview and the viewer's thumbnail strip and
 *   placeholder (a cell thumbnail is 24px, a 3 MB screenshot becomes ~20 KB).
 * - `preview`: at most 2048px, WebP, only when the original is bigger than
 *   that or heavy. The viewer opens on it and only fetches the original when
 *   someone zooms past it. Not made for an animated GIF, so it keeps playing.
 *
 * The real `width` and `height` are returned too, the viewer lays the image
 * out from them instead of downloading it first to measure it. Photos are
 * turned upright from their EXIF orientation before anything is written.
 *
 * Generating them never blocks an upload: any failure (an unreadable or huge
 * image) is logged and the entry simply has no variants, the frontend then
 * falls back to the original URL everywhere.
 */
class CellImageVariantService
{
    public const THUMB_MAX_SIZE = 480;

    public const PREVIEW_MAX_SIZE = 2048;

    /** An original lighter than this and within PREVIEW_MAX_SIZE is already fine to show as is. */
    public const PREVIEW_MIN_BYTES = 600 * 1024;

    /** Above this many pixels decoding could use hundreds of MB, such an image keeps only its original. */
    public const MAX_PIXELS = 40_000_000;

    private const THUMB_QUALITY = 78;

    private const PREVIEW_QUALITY = 82;

    /** Variant names are random UUIDs that never change, so browsers and CDNs can keep them for good. */
    private const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    private const SUPPORTED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public static function supports(?string $mime_type): bool
    {
        return in_array($mime_type, self::SUPPORTED_MIME_TYPES, true);
    }

    /**
     * Generates the variants of an image file on the local filesystem (an
     * upload's temp file, or a copy of a stored original) and stores them in
     * `$directory` on the app disk.
     *
     * @return array{width: int, height: int, thumb_path: string|null, thumb_url: string|null, preview_path: string|null, preview_url: string|null, preview_width: int|null}|null
     */
    public function generate(string $local_path, string $directory): ?array
    {
        try {
            $size = @getimagesize($local_path);
            if ($size === false || $size[0] * $size[1] > self::MAX_PIXELS) {
                return null;
            }

            $image = $this->manager()->read($local_path);
            $is_animated = $image->isAnimated();
            if ($is_animated) {
                $image->removeAnimation();
            }

            // Measured after the EXIF rotation, a portrait photo reports portrait sizes.
            $width = $image->width();
            $height = $image->height();

            $needs_preview = ! $is_animated
                && (max($width, $height) > self::PREVIEW_MAX_SIZE || (int) filesize($local_path) > self::PREVIEW_MIN_BYTES);

            $preview = $needs_preview ? $this->store(clone $image, self::PREVIEW_MAX_SIZE, self::PREVIEW_QUALITY, $directory) : null;
            $thumb = $this->store($image, self::THUMB_MAX_SIZE, self::THUMB_QUALITY, $directory);

            return [
                'width' => $width,
                'height' => $height,
                'thumb_path' => $thumb['path'],
                'thumb_url' => $thumb['url'],
                'preview_path' => $preview['path'] ?? null,
                'preview_url' => $preview['url'] ?? null,
                'preview_width' => $preview['width'] ?? null,
            ];
        } catch (Throwable $exception) {
            Log::warning('Files cell image variants could not be generated.', [
                'path' => $local_path,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Same as {@see generate()} for an original already on the app disk, used by the backfill command.
     *
     * @return array<string, mixed>|null
     */
    public function generateForStoredFile(string $stored_path): ?array
    {
        $disk = $this->disk();
        if (! $disk->exists($stored_path)) {
            return null;
        }

        $temp_path = tempnam(sys_get_temp_dir(), 'cell-image-');
        try {
            file_put_contents($temp_path, $disk->get($stored_path));

            return $this->generate($temp_path, dirname($stored_path));
        } finally {
            @unlink($temp_path);
        }
    }

    /**
     * Removes the variants of a Files cell entry, the original is handled by the caller.
     *
     * @param  array<string, mixed>  $file
     */
    public function deleteVariants(array $file): void
    {
        foreach (['thumb_path', 'preview_path'] as $key) {
            $path = (string) ($file[$key] ?? '');
            if ($path !== '' && $this->disk()->exists($path)) {
                $this->disk()->delete($path);
            }
        }
    }

    /**
     * @return array{path: string, url: string, width: int}
     */
    private function store(ImageInterface $image, int $max_size, int $quality, string $directory): array
    {
        $image->scaleDown($max_size, $max_size);
        $path = trim($directory, '/').'/'.Str::uuid().'.webp';

        $this->disk()->put($path, $image->toWebp($quality)->toString(), [
            'ContentType' => 'image/webp',
            'CacheControl' => self::CACHE_CONTROL,
        ]);

        return ['path' => $path, 'url' => $this->disk()->url($path), 'width' => $image->width()];
    }

    private function manager(): ImageManager
    {
        // `strip` drops EXIF (camera, GPS location) from the copies, the original is left untouched.
        return ImageManager::gd(autoOrientation: true, decodeAnimation: true, strip: true);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.app_disk'));
    }
}
