<?php

namespace App\Services\Workspace;

use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores/removes a workspace's cover image, the wide banner shown at the top
 * of Manage Workspace. Follows the same store-then-persist-path convention as
 * {@see WorkspaceAvatarService}, but instead of keeping the raw upload it
 * re-encodes a single WebP capped at {@see self::MAX_WIDTH}, so a 6000px
 * camera photo never ships to every visitor of the page.
 */
class WorkspaceCoverService
{
    private const DIRECTORY = 'workspace-covers';

    /** Wide enough for a 2x retina banner on a large monitor. */
    private const MAX_WIDTH = 2400;

    private const QUALITY = 82;

    /**
     * Replaces the workspace's cover with the uploaded file, deleting
     * whatever it previously had.
     */
    public function store(Workspace $workspace, UploadedFile $file, int $position_y = 50): Workspace
    {
        $encoded = $this->encodeCover((string) $file->getRealPath());

        $this->purgeFile($workspace);

        $cover_path = self::DIRECTORY."/{$workspace->id}/".Str::uuid().'.webp';
        Storage::disk(config('filesystems.app_disk'))->put($cover_path, $encoded);

        $workspace->update([
            'cover_path' => $cover_path,
            'cover_position_y' => $position_y,
        ]);

        return $workspace;
    }

    /**
     * Moves the cover's vertical focal point without re-uploading it.
     */
    public function reposition(Workspace $workspace, int $position_y): Workspace
    {
        $workspace->update(['cover_position_y' => $position_y]);

        return $workspace;
    }

    /**
     * Removes the workspace's cover, reverting the banner to its default image.
     */
    public function destroy(Workspace $workspace): Workspace
    {
        $this->purgeFile($workspace);

        $workspace->update([
            'cover_path' => null,
            'cover_position_y' => 50,
        ]);

        return $workspace;
    }

    /**
     * Downscales the source image to at most {@see self::MAX_WIDTH} wide
     * (never upscales) and encodes it as WebP.
     */
    private function encodeCover(string $source_path): string
    {
        $source_data = file_get_contents($source_path);
        $image = $source_data !== false ? @imagecreatefromstring($source_data) : false;
        if ($image === false) {
            throw new RuntimeException('Unable to read the uploaded image.');
        }

        $source_width = imagesx($image);
        $source_height = imagesy($image);

        if ($source_width > self::MAX_WIDTH) {
            $target_width = self::MAX_WIDTH;
            $target_height = (int) round($source_height * (self::MAX_WIDTH / $source_width));

            $resized = imagecreatetruecolor($target_width, $target_height);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $target_width, $target_height, $source_width, $source_height);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        imagewebp($image, quality: self::QUALITY);
        $encoded = (string) ob_get_clean();

        imagedestroy($image);

        return $encoded;
    }

    private function purgeFile(Workspace $workspace): void
    {
        $path = $workspace->cover_path;
        if ($path && Storage::disk(config('filesystems.app_disk'))->exists($path)) {
            Storage::disk(config('filesystems.app_disk'))->delete($path);
        }
    }
}
