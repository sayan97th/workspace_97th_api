<?php

namespace App\Models;

use App\Http\Controllers\Board\BoardImportController;
use App\Jobs\ProcessBoardImportJob;
use App\Services\Board\BoardItemImportService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tracks one run of the "Import items" wizard's background job (see
 * {@see ProcessBoardImportJob}) — created by
 * {@see BoardImportController::commit()} the
 * moment the wizard's "Import Now" is pressed, then updated chunk-by-chunk
 * as {@see BoardItemImportService::commit()} works
 * through the uploaded rows, so the frontend's progress bar
 * (`useBoardImportProgress`) has something to poll/listen to regardless of
 * how large the file is. `cancel_requested` is the wizard's "stop" button —
 * the job checks it between chunks and stops early, keeping whatever rows
 * it already committed rather than rolling them back.
 *
 * @property int $id
 * @property int $board_id
 * @property int $board_view_id
 * @property int|null $group_id
 * @property int $user_id
 * @property string $import_token
 * @property string $file_name
 * @property string $status
 * @property array<string, mixed> $options
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $skipped_count
 * @property int $columns_created
 * @property bool $cancel_requested
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardView $boardView
 * @property-read BoardGroup|null $group
 * @property-read User $user
 */
#[Fillable([
    'board_id', 'board_view_id', 'group_id', 'user_id', 'import_token', 'file_name', 'status', 'options',
    'total_rows', 'processed_rows', 'created_count', 'updated_count', 'skipped_count', 'columns_created',
    'cancel_requested', 'error_message', 'started_at', 'finished_at',
])]
class BoardImportJob extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses the job is done with — no further progress updates will arrive for it. */
    public const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED];

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

    /** 0-100, rounded — `total_rows` is only known once the job's setup step resolves, so this reads 0 while still `"queued"`. */
    public function percent(): int
    {
        if ($this->total_rows <= 0) {
            return $this->status === self::STATUS_COMPLETED ? 100 : 0;
        }

        return (int) min(100, round($this->processed_rows / $this->total_rows * 100));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_count' => 'integer',
            'columns_created' => 'integer',
            'cancel_requested' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
