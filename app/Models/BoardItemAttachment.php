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
 * A file attached directly to a {@link BoardItem} (the Kanban drawer's
 * "Attachments" affordance), stored on the `public` disk under
 * `board-item-attachments/{item_id}/`, mirroring
 * {@see BoardItemCommentAttachment}'s convention. Deliberately independent
 * of comments — attaching a file this way does not create a comment, unlike
 * a file sent along with a typed update (see `BoardItemCommentController`).
 *
 * @property int $id
 * @property int $item_id
 * @property int|null $uploaded_by_id
 * @property string $file_name
 * @property string $file_path
 * @property string $extension
 * @property string $mime_type
 * @property int $size_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $download_url
 * @property-read BoardItem $item
 * @property-read User|null $uploader
 */
#[Fillable(['item_id', 'uploaded_by_id', 'file_name', 'file_path', 'extension', 'mime_type', 'size_bytes'])]
#[Appends(['download_url'])]
class BoardItemAttachment extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<BoardItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'item_id');
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
