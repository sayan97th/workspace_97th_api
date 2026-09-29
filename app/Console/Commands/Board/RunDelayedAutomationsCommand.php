<?php

namespace App\Console\Commands\Board;

use App\Services\Board\BoardAutomationService;
use Illuminate\Console\Command;

// php artisan automations:run-delayed
class RunDelayedAutomationsCommand extends Command
{
    protected $signature = 'automations:run-delayed';

    protected $description = 'Continues every board automation run whose "wait" step is over, see BoardAutomation::ACTION_WAIT';

    public function __construct(private readonly BoardAutomationService $automation_service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $continued = $this->automation_service->runDelayedRuns();

        $this->components->info("Continued {$continued} waiting automation run(s).");

        return self::SUCCESS;
    }
}
