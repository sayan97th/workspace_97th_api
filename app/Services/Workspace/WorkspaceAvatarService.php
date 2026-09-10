<?php

namespace App\Services\Workspace;

use App\Http\Controllers\Admin\AccountSetting\BrandingController;
use App\Http\Controllers\Profile\ProfilePhotoController;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores/removes a workspace's custom avatar image, mirroring the
 * store-then-persist-path convention used by {@see BrandingController}
 * and {@see ProfilePhotoController}, plus a
 * generated square thumbnail for the small badges rendered across the
 * sidebar switcher / browse modal.
 */
class WorkspaceAvatarService
{
    private const DIRECTORY = 'workspace-avatars';

    private const THUMBNAIL_SIZE = 160;

    /**
     * Replaces the workspace's avatar with the uploaded file, deleting
     * whatever it previously had.
     */
    public function store(Workspace $workspace, UploadedFile $file): Workspace
    {
        $this->purgeFiles($workspace);

        $avatar_path = $file->storeAs(
            self::DIRECTORY."/{$workspace->id}",
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            config('filesystems.app_disk')
        );

        $thumbnail_path = self::DIRECTORY."/{$workspace->id}/thumbnails/".Str::uuid().'.webp';
        Storage::disk(config('filesystems.app_disk'))->put(
            $thumbnail_path,
            $this->makeSquareThumbnail((string) $file->getRealPath(), self::THUMBNAIL_SIZE)
        );

        $workspace->update([
            'avatar_path' => $avatar_path,
            'avatar_thumbnail_path' => $thumbnail_path,
        ]);

        return $workspace;
    }

    /**
     * Removes the workspace's avatar, reverting it to its generated
     * mono/color badge.
     */
    public function destroy(Workspace $workspace): Workspace
    {
        $this->purgeFiles($workspace);

        $workspace->update([
            'avatar_path' => null,
            'avatar_thumbnail_path' => null,
        ]);

        return $workspace;
    }

    /**
     * Center-crops the source image to a square and downsamples it to
     * `$size`x`$size`, so every workspace badge renders a consistently sized
     * thumbnail regardless of the aspect ratio that was uploaded. Encoded as
     * WebP with an alpha channel preserved, so a cropped PNG/GIF upload keeps
     * its transparency.
     */
    private function makeSquareThumbnail(string $source_path, int $size): string
    {
        $source_data = file_get_contents($source_path);
        $image = $source_data !== false ? @imagecreatefromstring($source_data) : false;
        if ($image === false) {
            throw new RuntimeException('Unable to read the uploaded image.');
        }

        $source_width = imagesx($image);
        $source_height = imagesy($image);
        $crop_edge = min($source_width, $source_height);
        $crop_x = (int) (($source_width - $crop_edge) / 2);
        $crop_y = (int) (($source_height - $crop_edge) / 2);

        $thumbnail = imagecreatetruecolor($size, $size);
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
        imagefill($thumbnail, 0, 0, $transparent);

        imagecopyresampled(
            $thumbnail,
            $image,
            0,
            0,
            $crop_x,
            $crop_y,
            $size,
            $size,
            $crop_edge,
            $crop_edge
        );

        ob_start();
        imagewebp($thumbnail, quality: 85);
        $encoded = (string) ob_get_clean();

        imagedestroy($image);
        imagedestroy($thumbnail);

        return $encoded;
    }

    private function purgeFiles(Workspace $workspace): void
    {
        $this->deleteIfExists($workspace->avatar_path);
        $this->deleteIfExists($workspace->avatar_thumbnail_path);
    }

    private function deleteIfExists(?string $path): void
    {
        if ($path && Storage::disk(config('filesystems.app_disk'))->exists($path)) {
            Storage::disk(config('filesystems.app_disk'))->delete($path);
        }
    }
}
