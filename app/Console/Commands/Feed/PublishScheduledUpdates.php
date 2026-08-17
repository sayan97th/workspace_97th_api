<?php

namespace App\Console\Commands\Feed;

use App\Services\Feed\FeedService;
use Illuminate\Console\Command;

// php artisan feed:publish-scheduled
class PublishScheduledUpdates extends Command
{
    protected $signature = 'feed:publish-scheduled';

    protected $description = 'Publish Update Feed entries whose scheduled_at has come due — notifies and broadcasts them the same way a fresh comment would be';

    public function __construct(private readonly FeedService $feed_service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $published = $this->feed_service->publishDue();

        $this->components->info("Published {$published} scheduled update(s).");

        return self::SUCCESS;
    }
}
