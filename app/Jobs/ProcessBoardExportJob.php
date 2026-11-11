<?php

namespace App\Jobs;

use App\Events\BoardExportProgressUpdated;
use App\Http\Controllers\Board\BoardExportController;
use App\Models\BoardExportJob;
use App\Services\Board\Export\BoardExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one "Export board to Excel" workbook in the background, dispatched
 * by {@see BoardExportController::store()} onto the dedicated `exports` queue
 * (its own Horizon supervisor, see config/horizon.php), so neither web
 * requests nor the default queue's automations and notifications ever wait
 * behind a large export.
 *
 * The heavy lifting is {@see BoardExportService::run()}. This job only keeps
 * the {@see BoardExportJob} row up to date and broadcasts a
 * {@see BoardExportProgressUpdated} as it goes (at most every
 * {@see PROGRESS_INTERVAL_SECONDS}, so a fast export doesn't flood the socket).
 *
 * `$tries = 1`: a failed export is reported as failed so the person can
 * press "Try again", rather than silently running a second time.
 */
class ProcessBoardExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Long enough for a board of several hundred thousand rows. */
    public int $timeout = 1800;

    private const PROGRESS_INTERVAL_SECONDS = 0.75;

    public function __construct(public int $board_export_job_id)
    {
        $this->onQueue(BoardExportJob::QUEUE);

        // On Redis, the `redis-exports` connection's long `retry_after` keeps a running export
        // from being handed to a second worker. Other drivers (sync in tests) stay as they are.
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-exports');
        }
    }

    public function handle(BoardExportService $exporter): void
    {
        $export_job = BoardExportJob::find($this->board_export_job_id);

        if ($export_job === null || $export_job->status !== BoardExportJob::STATUS_QUEUED) {
            return;
        }

        if ($export_job->cancel_requested) {
            $this->finish($export_job, ['status' => BoardExportJob::STATUS_CANCELLED]);

            return;
        }

        $export_job->update(['status' => BoardExportJob::STATUS_PROCESSING, 'started_at' => now()]);
        $this->broadcastProgress($export_job);

        $last_broadcast_at = 0.0;

        try {
            $result = $exporter->run(
                $export_job,
                onProgress: function (int $processed, int $total) use ($export_job, &$last_broadcast_at) {
                    $export_job->forceFill(['processed_rows' => $processed, 'total_rows' => $total])->save();

                    $now = microtime(true);
                    if ($now - $last_broadcast_at >= self::PROGRESS_INTERVAL_SECONDS) {
                        $last_broadcast_at = $now;
                        $this->broadcastProgress($export_job);
                    }
                },
                isCancelled: fn () => (bool) BoardExportJob::whereKey($export_job->id)->value('cancel_requested'),
            );
        } catch (Throwable $exception) {
            Log::error('Board export failed', [
                'export_job_id' => $export_job->id,
                'board_id' => $export_job->board_id,
                'exception' => $exception->getMessage(),
            ]);
            $this->finish($export_job, [
                'status' => BoardExportJob::STATUS_FAILED,
                'error_message' => 'The export could not be completed. Please try again.',
            ]);

            return;
        }

        if ($result['cancelled']) {
            $this->finish($export_job, [
                'status' => BoardExportJob::STATUS_CANCELLED,
                'processed_rows' => $result['processed_rows'],
                'total_rows' => $result['total_rows'],
            ]);

            return;
        }

        $this->finish($export_job, [
            'status' => BoardExportJob::STATUS_COMPLETED,
            'disk' => $result['disk'],
            'file_path' => $result['file_path'],
            'file_size_bytes' => $result['file_size_bytes'],
            'processed_rows' => $result['processed_rows'],
            'total_rows' => $result['total_rows'],
            'column_count' => $result['column_count'],
            'expires_at' => now()->addHours(BoardExportJob::RETENTION_HOURS),
        ]);
    }

    /**
     * Catches what `handle()` itself could not (a timeout, a fatal error), so the dialog's
     * progress bar never waits forever.
     */
    public function failed(?Throwable $exception): void
    {
        $export_job = BoardExportJob::find($this->board_export_job_id);

        if ($export_job !== null && ! $export_job->isTerminal()) {
            $this->finish($export_job, [
                'status' => BoardExportJob::STATUS_FAILED,
                'error_message' => 'The export took too long or stopped unexpectedly. Please try again.',
            ]);
        }
    }

    /**
     * A websocket server that is down must never fail the export, the dialog falls back to
     * polling the status endpoint.
     */
    private function broadcastProgress(BoardExportJob $export_job): void
    {
        try {
            broadcast(new BoardExportProgressUpdated($export_job));
        } catch (Throwable $exception) {
            Log::warning('Board export progress broadcast failed', ['export_job_id' => $export_job->id, 'exception' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finish(BoardExportJob $export_job, array $attributes): void
    {
        $export_job->update([...$attributes, 'finished_at' => now()]);
        $this->broadcastProgress($export_job);
    }
}
