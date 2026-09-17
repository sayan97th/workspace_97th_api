<?php

namespace App\Console\Commands\Board;

use App\Services\Board\RecurringItemService;
use Illuminate\Console\Command;

// php artisan items:run-recurrences
class RunItemRecurrencesCommand extends Command
{
    protected $signature = 'items:run-recurrences';

    protected $description = 'Recreates every item whose recurring schedule is due today — see RecurringItemService';

    public function __construct(private readonly RecurringItemService $recurring_item_service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $recurred_count = $this->recurring_item_service->run();

        $this->components->info("Recreated {$recurred_count} recurring item(s).");

        return self::SUCCESS;
    }
}
