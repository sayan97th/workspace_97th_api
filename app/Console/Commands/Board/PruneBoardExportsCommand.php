<?php

namespace App\Console\Commands\Board;

use App\Models\BoardExportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes board export files once they expire (see
 * {@see BoardExportJob::RETENTION_HOURS}), marks long stuck runs as failed,
 * clears leftover temporary files and drops old export records. Scheduled
 * hourly in routes/console.php.
 */
class PruneBoardExportsCommand extends Command
{
    protected $signature = 'board-exports:prune';

    protected $description = 'Delete expired board export files and old export records';

    /** A run with no progress for this long lost its worker. */
    private const STUCK_AFTER_HOURS = 2;

    /** Export records (no file anymore) are kept this long for the record. */
    private const KEEP_RECORDS_DAYS = 30;

    public function handle(): int
    {
        $deleted_files = 0;

        BoardExportJob::query()
            ->whereNotNull('file_path')
            ->where('expires_at', '<', now())
            ->chunkById(200, function ($export_jobs) use (&$deleted_files) {
                foreach ($export_jobs as $export_job) {
                    try {
                        Storage::disk($export_job->disk ?? config('filesystems.default'))->delete($export_job->file_path);
                    } catch (Throwable $exception) {
                        $this->warn("Could not delete {$export_job->file_path}: {$exception->getMessage()}");

                        continue;
                    }
                    $export_job->update(['file_path' => null]);
                    $deleted_files++;
                }
            });

        $stuck = BoardExportJob::query()
            ->whereIn('status', [BoardExportJob::STATUS_QUEUED, BoardExportJob::STATUS_PROCESSING])
            ->where('updated_at', '<', now()->subHours(self::STUCK_AFTER_HOURS))
            ->update([
                'status' => BoardExportJob::STATUS_FAILED,
                'error_message' => 'The export stopped unexpectedly. Please try again.',
                'finished_at' => now(),
            ]);

        $old_records = BoardExportJob::query()
            ->whereNull('file_path')
            ->where('created_at', '<', now()->subDays(self::KEEP_RECORDS_DAYS))
            ->delete();

        // Temporary files of a worker that died mid export.
        $temp_directory = storage_path('app/board-export-tmp');
        $temp_files = 0;
        if (File::isDirectory($temp_directory)) {
            foreach (File::files($temp_directory) as $file) {
                if ($file->getMTime() < now()->subHours(self::STUCK_AFTER_HOURS)->getTimestamp()) {
                    File::delete($file->getPathname());
                    $temp_files++;
                }
            }
        }

        $this->info("Deleted {$deleted_files} expired files, {$temp_files} temporary files and {$old_records} old records. Marked {$stuck} stuck exports as failed.");

        return self::SUCCESS;
    }
}
