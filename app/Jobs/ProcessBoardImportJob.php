<?php

namespace App\Jobs;

use App\Events\BoardImportProgressUpdated;
use App\Http\Controllers\Board\BoardImportController;
use App\Models\BoardImportJob;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardItemImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the "Import items" wizard's actual write side in the background —
 * dispatched by {@see BoardImportController::commit()}
 * the moment "Import Now" is pressed, so a large upload's row-by-row work
 * never risks timing out an HTTP request. Delegates the chunked writing
 * itself to {@see BoardItemImportService::commit()}, and this job's own job
 * is just the surrounding bookkeeping: flip the {@see BoardImportJob} row's
 * status, broadcast a {@see BoardImportProgressUpdated} after every chunk
 * (see `useBoardImportProgress` on the frontend), and let the service know
 * when `cancel_requested` has been set so it stops between chunks instead of
 * mid-write.
 *
 * Reads its rows from `$import_job->parsed_payload` rather than
 * {@see BoardItemImportService::loadParsedImport()}'s token-keyed disk
 * cache — this job runs on whatever queue worker picked it up, which isn't
 * guaranteed to share a filesystem with the web process that handled
 * `commit()`, so the payload travels through the database (the one thing
 * both processes definitely share) instead.
 *
 * Deliberately `$tries = 1` — a retried run would re-execute `commit()` from
 * scratch against a token whose rows may already be partially imported
 * (`"add"` duplicate mode has no way to tell an already-imported row apart
 * from a genuinely new one), so a failure is surfaced as `STATUS_FAILED`
 * instead of silently risking duplicate rows on retry.
 */
class ProcessBoardImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;

    /**
     * Generous — a multi-thousand-row file, chunked, can legitimately take
     * several minutes; this takes precedence over the queue worker's own
     * `--timeout`, so a large import isn't killed by the default 60s cap.
     */
    public int $timeout = 1800;

    public function __construct(public int $board_import_job_id) {}

    public function handle(BoardItemImportService $importer): void
    {
        $import_job = BoardImportJob::find($this->board_import_job_id);

        if ($import_job === null || $import_job->status !== BoardImportJob::STATUS_QUEUED) {
            return; // Already handled (or its row was deleted) — nothing to do.
        }

        if ($import_job->cancel_requested) {
            $import_job->update(['status' => BoardImportJob::STATUS_CANCELLED, 'parsed_payload' => null, 'finished_at' => now()]);
            broadcast(new BoardImportProgressUpdated($import_job));

            return;
        }

        $import_job->update(['status' => BoardImportJob::STATUS_PROCESSING, 'started_at' => now()]);
        broadcast(new BoardImportProgressUpdated($import_job));

        $board = WorkspaceNavigationItem::find($import_job->board_id);
        $view = BoardView::find($import_job->board_view_id);
        $parsed = $import_job->parsed_payload;

        if ($board === null || $view === null || $parsed === null) {
            $this->markFailed($import_job, 'The uploaded file could no longer be found — please try the import again.');

            return;
        }

        try {
            $result = $importer->commit(
                board: $board,
                view: $view,
                parsed: $parsed,
                options: $this->resolveOptions($import_job->options),
                onChunkProcessed: function (int $processed, int $total) use ($import_job) {
                    $import_job->update(['processed_rows' => $processed, 'total_rows' => $total]);
                    broadcast(new BoardImportProgressUpdated($import_job));
                },
                isCancelled: fn () => $import_job->fresh()->cancel_requested,
            );
        } catch (Throwable $exception) {
            Log::error('Board import failed', ['import_job_id' => $import_job->id, 'exception' => $exception->getMessage()]);
            $this->markFailed($import_job, 'The import failed partway through — rows already imported were kept.');

            return;
        }

        $import_job->update([
            'status' => $result['cancelled'] ? BoardImportJob::STATUS_CANCELLED : BoardImportJob::STATUS_COMPLETED,
            'processed_rows' => $result['processed_rows'],
            'total_rows' => $result['total_rows'],
            'created_count' => $result['created'],
            'updated_count' => $result['updated'],
            'skipped_count' => $result['skipped'],
            'columns_created' => $result['columns_created'],
            'group_id' => $result['group_id'],
            'parsed_payload' => null,
            'finished_at' => now(),
        ]);
        broadcast(new BoardImportProgressUpdated($import_job));
    }

    /**
     * Catches an exception the queue's own retry/`failed_jobs` machinery
     * never gets to see (e.g. a fatal error mid-`handle()`), so the wizard's
     * progress bar doesn't spin forever waiting for an update that will
     * never arrive.
     */
    public function failed(?Throwable $exception): void
    {
        $import_job = BoardImportJob::find($this->board_import_job_id);

        if ($import_job !== null && ! in_array($import_job->status, BoardImportJob::TERMINAL_STATUSES, true)) {
            $this->markFailed($import_job, 'The import failed unexpectedly. Please try again.');
        }
    }

    private function markFailed(BoardImportJob $import_job, string $message): void
    {
        $import_job->update([
            'status' => BoardImportJob::STATUS_FAILED,
            'error_message' => $message,
            'parsed_payload' => null,
            'finished_at' => now(),
        ]);
        broadcast(new BoardImportProgressUpdated($import_job));
    }

    /**
     * Re-shapes `$import_job->options` (an untyped JSON blob once it's come
     * back through the `array` cast) into exactly what
     * {@see BoardItemImportService::commit()} declares it needs — the
     * controller wrote it in this shape to begin with (see
     * `CommitBoardImportRequest`'s validated payload), so this is really
     * just restoring the static type Eloquent's JSON cast can't carry.
     *
     * @param  array<string, mixed>  $raw
     * @return array{target_group_id: int|null, new_group_name: string|null, mappings: array<int, array{source_index: int, mode: string, target_column_id: int|null, new_label: string|null, new_type: string|null}>, duplicate_mode: string, match_source_index: int|null}
     */
    private function resolveOptions(array $raw): array
    {
        $mappings = [];

        foreach ((array) ($raw['mappings'] ?? []) as $mapping) {
            if (! is_array($mapping) || ! isset($mapping['source_index'], $mapping['mode'])) {
                continue;
            }

            $mappings[] = [
                'source_index' => (int) $mapping['source_index'],
                'mode' => (string) $mapping['mode'],
                'target_column_id' => isset($mapping['target_column_id']) ? (int) $mapping['target_column_id'] : null,
                'new_label' => isset($mapping['new_label']) ? (string) $mapping['new_label'] : null,
                'new_type' => isset($mapping['new_type']) ? (string) $mapping['new_type'] : null,
            ];
        }

        return [
            'target_group_id' => isset($raw['target_group_id']) ? (int) $raw['target_group_id'] : null,
            'new_group_name' => isset($raw['new_group_name']) ? (string) $raw['new_group_name'] : null,
            'mappings' => $mappings,
            'duplicate_mode' => (string) ($raw['duplicate_mode'] ?? 'add'),
            'match_source_index' => isset($raw['match_source_index']) ? (int) $raw['match_source_index'] : null,
        ];
    }
}
