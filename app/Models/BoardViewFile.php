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
use Illuminate\Support\Str;

/**
 * A file uploaded to a `file_gallery`-type {@link BoardView}, stored on the
 * `public` disk under `board-view-files/{board_view_id}/`, the same
 * `Str::uuid()`-filename convention as {@link BoardItemCommentAttachment}.
 *
 * @property int $id
 * @property int $board_view_id
 * @property int|null $uploaded_by_id
 * @property string $file_name
 * @property string $file_path
 * @property string $extension
 * @property string $mime_type
 * @property int $size_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $download_url
 * @property-read bool $is_image
 * @property-read BoardView $view
 * @property-read User|null $uploader
 */
#[Fillable(['board_view_id', 'uploaded_by_id', 'file_name', 'file_path', 'extension', 'mime_type', 'size_bytes'])]
#[Appends(['download_url', 'is_image'])]
class BoardViewFile extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<BoardView, $this>
     */
    public function view(): BelongsTo
    {
        return $this->belongsTo(BoardView::class, 'board_view_id');
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
     * Whether the gallery card should render an image thumbnail instead of
     * the generic extension badge.
     *
     * @return Attribute<bool, never>
     */
    protected function isImage(): Attribute
    {
        return Attribute::make(
            get: fn () => Str::startsWith($this->mime_type, 'image/'),
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
