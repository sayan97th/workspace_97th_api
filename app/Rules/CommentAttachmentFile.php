<?php

namespace App\Rules;

use App\Services\Board\CommentAttachmentService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * One file attached to an update (item drawer, board discussion) or dropped
 * on an item's Files tab: documents, images and videos, including the screen
 * recordings made with the update composer's "Record" button.
 *
 * Videos get a much higher size cap than documents and images, a few minutes
 * of 1080p screen capture easily passes 50 MB. Uploads land on the app disk
 * (S3 in staging and production) through {@see CommentAttachmentService}.
 *
 * Use {@see self::rules()} so every endpoint shares the same list.
 */
class CommentAttachmentFile implements ValidationRule
{
    /** Documents and images, in KB. */
    public const MAX_FILE_KILOBYTES = 51200;

    /** Videos and screen recordings, in KB (500 MB). */
    public const MAX_VIDEO_KILOBYTES = 512000;

    /** @var list<string> */
    public const DOCUMENT_EXTENSIONS = ['pdf', 'xlsx', 'xls', 'csv', 'docx', 'doc', 'pptx', 'ppt'];

    /** @var list<string> */
    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** @var list<string> */
    public const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'm4v'];

    /**
     * The full rule list for one `attachments.*` (or `files.*`) entry.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        $extensions = implode(',', [...self::DOCUMENT_EXTENSIONS, ...self::IMAGE_EXTENSIONS, ...self::VIDEO_EXTENSIONS]);

        return ['file', 'mimes:'.$extensions, 'max:'.self::MAX_VIDEO_KILOBYTES, new self];
    }

    /**
     * True when the upload is a video, judged by its detected MIME type
     * first and its extension second (a browser recording is sometimes
     * reported as `application/octet-stream`).
     */
    public static function isVideo(UploadedFile $file): bool
    {
        $mime_type = (string) $file->getMimeType();
        if (str_starts_with($mime_type, 'video/')) {
            return true;
        }

        return in_array(strtolower($file->getClientOriginalExtension()), self::VIDEO_EXTENSIONS, true);
    }

    /**
     * Keeps the 50 MB cap on everything that is not a video, `max:` above
     * already caps videos at 500 MB.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || self::isVideo($value)) {
            return;
        }

        if ($value->getSize() > self::MAX_FILE_KILOBYTES * 1024) {
            $fail('Files other than videos may not be larger than 50 MB.');
        }
    }
}
