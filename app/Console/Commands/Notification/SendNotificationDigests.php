<?php

namespace App\Console\Commands\Notification;

use App\Enums\EmailDigestFrequency;
use App\Services\Notification\NotificationDigestService;
use Illuminate\Console\Command;

// php artisan notifications:send-digests daily
class SendNotificationDigests extends Command
{
    protected $signature = 'notifications:send-digests {frequency : daily or weekly}';

    protected $description = 'Email the unread-notifications digest to every user subscribed to the given frequency';

    public function __construct(private readonly NotificationDigestService $digest_service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $frequency = EmailDigestFrequency::tryFrom((string) $this->argument('frequency'));

        if ($frequency === null || $frequency === EmailDigestFrequency::Off) {
            $this->components->error('The frequency must be "daily" or "weekly".');

            return self::FAILURE;
        }

        $queued = $this->digest_service->sendDue($frequency);

        $this->components->info("Queued {$queued} {$frequency->value} digest email(s).");

        return self::SUCCESS;
    }
}
