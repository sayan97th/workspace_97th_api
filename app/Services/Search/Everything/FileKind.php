<?php

namespace App\Services\Search\Everything;

/**
 * The file type buckets of the Files tab ("Image", "Word", "PDF"...), decided by extension
 * first and MIME type second.
 */
final class FileKind
{
    public const IMAGE = 'image';

    public const WORD = 'word';

    public const POWERPOINT = 'powerpoint';

    public const EXCEL = 'excel';

    public const PDF = 'pdf';

    public const ZIP = 'zip';

    public const MUSIC = 'music';

    public const VIDEO = 'video';

    public const OTHER = 'other';

    /** In the order the facet checkboxes are listed. */
    public const ALL = [self::IMAGE, self::WORD, self::POWERPOINT, self::EXCEL, self::PDF, self::ZIP, self::MUSIC, self::VIDEO, self::OTHER];

    private const EXTENSIONS = [
        self::IMAGE => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'heic', 'heif', 'tif', 'tiff', 'avif', 'ico'],
        self::WORD => ['doc', 'docx', 'odt', 'rtf', 'pages'],
        self::POWERPOINT => ['ppt', 'pptx', 'odp', 'key'],
        self::EXCEL => ['xls', 'xlsx', 'xlsm', 'csv', 'ods', 'numbers'],
        self::PDF => ['pdf'],
        self::ZIP => ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2'],
        self::MUSIC => ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'wma'],
        self::VIDEO => ['mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv', 'm4v'],
    ];

    public static function classify(?string $extension, ?string $mime_type): string
    {
        $extension = strtolower(ltrim((string) $extension, '.'));
        foreach (self::EXTENSIONS as $kind => $extensions) {
            if ($extension !== '' && in_array($extension, $extensions, true)) {
                return $kind;
            }
        }

        $mime_type = strtolower((string) $mime_type);

        return match (true) {
            str_starts_with($mime_type, 'image/') => self::IMAGE,
            str_starts_with($mime_type, 'video/') => self::VIDEO,
            str_starts_with($mime_type, 'audio/') => self::MUSIC,
            $mime_type === 'application/pdf' => self::PDF,
            default => self::OTHER,
        };
    }

    public static function extensionOf(?string $file_name): string
    {
        return strtolower(pathinfo((string) $file_name, PATHINFO_EXTENSION));
    }
}
