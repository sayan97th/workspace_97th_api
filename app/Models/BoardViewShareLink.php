<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A read only public link to one board view ("Share view"). Anyone with the
 * token can open it, and when `password` is set they must also send it.
 *
 * @property int $id
 * @property int $board_id
 * @property int $board_view_id
 * @property string $token
 * @property string|null $password
 * @property bool $is_enabled
 * @property int|null $created_by_id
 * @property Carbon|null $last_accessed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardView $boardView
 */
#[Fillable(['board_id', 'board_view_id', 'token', 'password', 'is_enabled', 'created_by_id', 'last_accessed_at'])]
#[Hidden(['password'])]
class BoardViewShareLink extends Model
{
    /**
     * A fresh unguessable token for a public link.
     */
    public static function generateToken(): string
    {
        return Str::random(48);
    }

    /**
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * @return BelongsTo<BoardView, $this>
     */
    public function boardView(): BelongsTo
    {
        return $this->belongsTo(BoardView::class, 'board_view_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_enabled' => 'boolean',
            'last_accessed_at' => 'datetime',
        ];
    }
}
