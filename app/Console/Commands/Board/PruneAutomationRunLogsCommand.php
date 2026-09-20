<?php

namespace App\Console\Commands\Board;

use App\Models\BoardAutomationRunLog;
use Illuminate\Console\Command;

// php artisan automations:prune-run-logs
class PruneAutomationRunLogsCommand extends Command
{
    protected $signature = 'automations:prune-run-logs {--days=90 : Keep runs newer than this many days}';

    protected $description = 'Deletes automation run history older than the retention window, see BoardAutomationRunLog';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $deleted_count = BoardAutomationRunLog::where('created_at', '<', now()->subDays($days))->delete();

        $this->components->info("Deleted {$deleted_count} automation run log(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
