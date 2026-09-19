<?php

namespace App\Console\Commands\Notification;

use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;

// php artisan notifications:wake-snoozed
class WakeSnoozedNotifications extends Command
{
    protected $signature = 'notifications:wake-snoozed';

    protected $description = 'Resurface snoozed notifications whose snooze time has come, as unread and live over the websocket';

    public function __construct(private readonly NotificationService $notification_service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $woken = $this->notification_service->wakeSnoozed();

        $this->components->info("Woke {$woken} snoozed notification(s).");

        return self::SUCCESS;
    }
}
