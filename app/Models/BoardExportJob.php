<?php

namespace App\Models;

use App\Jobs\ProcessBoardExportJob;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tracks one run of the board options menu's "Export board to Excel" (or the
 * group menu's "Export to Excel"). Created the moment the export dialog's
 * "Export" button is pressed, then filled in chunk by chunk by
 * {@see ProcessBoardExportJob} on the dedicated `exports` queue, so a board
 * with tens of thousands of rows never ties up a web request. Once the
 * workbook is written it sits on `disk` at `file_path` until `expires_at`,
 * when `board-exports:prune` deletes it.
 *
 * @property int $id
 * @property int $board_id
 * @property int $board_view_id
 * @property int|null $group_id
 * @property int $user_id
 * @property string $status
 * @property array{layout?: string, include_subitems?: bool, include_hidden_columns?: bool, time_zone?: string} $options
 * @property string $file_name
 * @property string|null $disk
 * @property string|null $file_path
 * @property int $file_size_bytes
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $column_count
 * @property bool $cancel_requested
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardView $boardView
 * @property-read BoardGroup|null $group
 * @property-read User $user
 */
#[Fillable([
    'board_id', 'board_view_id', 'group_id', 'user_id', 'status', 'options', 'file_name', 'disk', 'file_path',
    'file_size_bytes', 'total_rows', 'processed_rows', 'column_count', 'cancel_requested', 'error_message',
    'started_at', 'finished_at', 'expires_at', 'downloaded_at',
])]
class BoardExportJob extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses the job is done with, no further progress updates will arrive for it. */
    public const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    /** One block per group with its own header row, the way the board reads (monday's own export layout). */
    public const LAYOUT_GROUPED = 'grouped';

    /** One header row and a Group column, ready for filters, sorting and pivot tables. */
    public const LAYOUT_FLAT = 'flat';

    public const LAYOUTS = [self::LAYOUT_GROUPED, self::LAYOUT_FLAT];

    /** The queue every export runs on, so a large one never delays automations or emails. */
    public const QUEUE = 'exports';

    /** How long a finished workbook stays downloadable. */
    public const RETENTION_HOURS = 24;

    /** How many exports one person may have queued or running at once. */
    public const MAX_ACTIVE_PER_USER = 3;

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
     * @return BelongsTo<BoardGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(BoardGroup::class, 'group_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /** Whether the finished workbook can still be downloaded. */
    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->file_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** 0 to 100, rounded. `total_rows` is only known once the job starts, so this reads 0 while queued. */
    public function percent(): int
    {
        if ($this->total_rows <= 0) {
            return $this->status === self::STATUS_COMPLETED ? 100 : 0;
        }

        return (int) min(100, round($this->processed_rows / $this->total_rows * 100));
    }

    public function layout(): string
    {
        $layout = $this->options['layout'] ?? self::LAYOUT_GROUPED;

        return in_array($layout, self::LAYOUTS, true) ? $layout : self::LAYOUT_GROUPED;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'file_size_bytes' => 'integer',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'column_count' => 'integer',
            'cancel_requested' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }
}
