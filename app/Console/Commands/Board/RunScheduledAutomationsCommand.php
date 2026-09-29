<?php

namespace App\Console\Commands\Board;

use App\Services\Board\BoardAutomationService;
use Illuminate\Console\Command;

// php artisan automations:run-scheduled
class RunScheduledAutomationsCommand extends Command
{
    protected $signature = 'automations:run-scheduled';

    protected $description = 'Runs every recurring board automation whose schedule came due, see BoardAutomation::TRIGGER_RECURRING';

    public function __construct(private readonly BoardAutomationService $automation_service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $ran_count = $this->automation_service->runScheduledTriggers();

        $this->components->info("Ran {$ran_count} recurring automation(s).");

        return self::SUCCESS;
    }
}
