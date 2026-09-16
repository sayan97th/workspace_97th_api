<?php

namespace App\Console\Commands\Board;

use App\Services\Board\BoardAutomationService;
use Illuminate\Console\Command;

// php artisan automations:run-date-triggers
class RunDueDateAutomationsCommand extends Command
{
    protected $signature = 'automations:run-date-triggers';

    protected $description = 'Runs every board automation whose date-column trigger has arrived today — see BoardAutomation::TRIGGER_DATE_ARRIVED';

    public function __construct(private readonly BoardAutomationService $automation_service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $ran_count = $this->automation_service->runDueDateTriggers();

        $this->components->info("Ran {$ran_count} date-triggered automation(s).");

        return self::SUCCESS;
    }
}
