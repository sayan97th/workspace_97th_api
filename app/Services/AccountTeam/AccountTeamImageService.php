<?php

namespace App\Services\AccountTeam;

use App\Models\AccountTeam;
use App\Services\Workspace\WorkspaceCoverService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores and removes a team's profile picture and cover image. Same convention as
 * {@see WorkspaceCoverService}: the upload is re-encoded as a single
 * WebP capped to the size the Teams page actually shows, so a camera photo never ships as is.
 */
class AccountTeamImageService
{
    public const KIND_PICTURE = 'picture';

    public const KIND_COVER = 'cover';

    /** Where a cover is framed when nobody has repositioned it, the middle of the image. */
    public const DEFAULT_COVER_POSITION_Y = 50;

    /** Longest side of the stored image per kind, 2x the largest size the page renders. */
    private const MAX_SIZES = [
        self::KIND_PICTURE => 400,
        self::KIND_COVER => 2400,
    ];

    private const QUALITY = 82;

    /**
     * Replaces the team's picture or cover with the uploaded file, deleting the previous one.
     * A new cover starts framed at `$cover_position_y`.
     */
    public function store(AccountTeam $team, string $kind, UploadedFile $file, int $cover_position_y = self::DEFAULT_COVER_POSITION_Y): AccountTeam
    {
        $encoded = $this->encode((string) $file->getRealPath(), self::MAX_SIZES[$kind]);

        $this->purge($team, $kind);

        $path = "account-teams/{$team->id}/{$kind}-".Str::uuid().'.webp';
        Storage::disk(config('filesystems.app_disk'))->put($path, $encoded);

        $attributes = [$this->column($kind) => $path];
        if ($kind === self::KIND_COVER) {
            $attributes['cover_position_y'] = $cover_position_y;
        }
        $team->update($attributes);

        return $team;
    }

    /**
     * Saves the cover's vertical focal point, 0 (top) to 100 (bottom).
     */
    public function reposition(AccountTeam $team, int $cover_position_y): AccountTeam
    {
        $team->update(['cover_position_y' => $cover_position_y]);

        return $team;
    }

    /**
     * Removes the team's picture or cover, the page falls back to its default art.
     */
    public function destroy(AccountTeam $team, string $kind): AccountTeam
    {
        $this->purge($team, $kind);

        $attributes = [$this->column($kind) => null];
        if ($kind === self::KIND_COVER) {
            $attributes['cover_position_y'] = self::DEFAULT_COVER_POSITION_Y;
        }
        $team->update($attributes);

        return $team;
    }

    private function column(string $kind): string
    {
        return $kind === self::KIND_COVER ? 'cover_path' : 'picture_path';
    }

    /**
     * Downscales the image so its longest side is at most `$max_size` (never upscales) and
     * encodes it as WebP.
     */
    private function encode(string $source_path, int $max_size): string
    {
        $source_data = file_get_contents($source_path);
        $image = $source_data !== false ? @imagecreatefromstring($source_data) : false;
        if ($image === false) {
            throw new RuntimeException('Unable to read the uploaded image.');
        }

        $source_width = imagesx($image);
        $source_height = imagesy($image);
        $scale = $max_size / max($source_width, $source_height);

        if ($scale < 1) {
            $target_width = max(1, (int) round($source_width * $scale));
            $target_height = max(1, (int) round($source_height * $scale));

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

    private function purge(AccountTeam $team, string $kind): void
    {
        $path = $team->{$this->column($kind)};
        if ($path && Storage::disk(config('filesystems.app_disk'))->exists($path)) {
            Storage::disk(config('filesystems.app_disk'))->delete($path);
        }
    }
}
