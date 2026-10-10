<?php

namespace App\Console\Commands\Board;

use App\Services\ExternalAccounts\EmailTriggerPoller;
use Illuminate\Console\Command;

// php artisan automations:poll-email
class PollEmailAutomationsCommand extends Command
{
    protected $signature = 'automations:poll-email';

    protected $description = 'Reads the connected Gmail and Outlook inboxes of every "email is received" automation, see BoardAutomation::TRIGGER_EMAIL_RECEIVED';

    public function __construct(private readonly EmailTriggerPoller $poller)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $ran_count = $this->poller->poll();

        $this->components->info("Turned {$ran_count} email(s) into automation runs.");

        return self::SUCCESS;
    }
}
