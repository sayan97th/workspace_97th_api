<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A file uploaded alongside a {@link BoardItemComment}, stored on the
 * `public` disk under `board-comment-attachments/{item_id}/`, the same
 * `Str::uuid()`-filename convention as `ProfilePhotoController`.
 *
 * @property int $id
 * @property int $comment_id
 * @property int|null $uploaded_by_id
 * @property string $file_name
 * @property string $file_path
 * @property string $extension
 * @property string $mime_type
 * @property int $size_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $download_url
 * @property-read BoardItemComment $comment
 * @property-read User|null $uploader
 */
#[Fillable(['comment_id', 'uploaded_by_id', 'file_name', 'file_path', 'extension', 'mime_type', 'size_bytes'])]
#[Appends(['download_url'])]
class BoardItemCommentAttachment extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<BoardItemComment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(BoardItemComment::class, 'comment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * @return Attribute<string, never>
     */
    protected function downloadUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => Storage::disk('public')->url($this->file_path),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }
}
