<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The body of a Doc, a navigation leaf whose `view_key` is `doc`. The content
 * is the editor's JSON document (ProseMirror/Tiptap) stored as text, so image
 * sizes, alignment, mentions and toggles survive a save, which plain markdown
 * can't express.
 *
 * @property int $id
 * @property int $board_id
 * @property string|null $content
 * @property string|null $public_token
 * @property bool $is_public_link_enabled
 * @property int|null $updated_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read User|null $updatedBy
 */
#[Fillable([
    'board_id',
    'content',
    'public_token',
    'is_public_link_enabled',
    'updated_by_id',
])]
class BoardDoc extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public_link_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
