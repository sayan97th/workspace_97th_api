<?php

namespace App\Console\Commands\Board;

use App\Services\Board\BoardAutomationService;
use Illuminate\Console\Command;

// php artisan automations:run-date-triggers
class RunDueDateAutomationsCommand extends Command
{
    protected $signature = 'automations:run-date-triggers';

    protected $description = 'Runs every board automation whose date-column trigger has arrived (BoardAutomation::TRIGGER_DATE_ARRIVED) whose items became overdue (BoardAutomation::TRIGGER_ITEM_OVERDUE), stuck in a status (TRIGGER_STATUS_STUCK) or not updated for a while (TRIGGER_ITEM_STALE)';

    public function __construct(private readonly BoardAutomationService $automation_service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $ran_count = $this->automation_service->runDueDateTriggers();
        $overdue_count = $this->automation_service->runOverdueTriggers();
        $quiet_count = $this->automation_service->runQuietTriggers();

        $this->components->info("Ran {$ran_count} date-triggered automation(s), {$overdue_count} overdue one(s) and {$quiet_count} stuck or not updated one(s).");

        return self::SUCCESS;
    }
}
