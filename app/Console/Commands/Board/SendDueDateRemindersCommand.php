<?php

namespace App\Console\Commands\Board;

use App\Services\Board\DueDateReminderService;
use Illuminate\Console\Command;

// php artisan board:send-due-date-reminders
class SendDueDateRemindersCommand extends Command
{
    protected $signature = 'board:send-due-date-reminders';

    protected $description = 'Notifies every person assigned to an item whose Date column reminder is due today — see DueDateReminderService';

    public function __construct(private readonly DueDateReminderService $reminder_service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $notified_count = $this->reminder_service->run();

        $this->components->info("Sent {$notified_count} due-date reminder notification(s).");

        return self::SUCCESS;
    }
}
