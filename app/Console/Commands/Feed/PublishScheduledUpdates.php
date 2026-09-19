<?php

namespace App\Console\Commands\Feed;

use App\Services\Board\ScheduledCommentService;
use Illuminate\Console\Command;

// php artisan feed:publish-scheduled
class PublishScheduledUpdates extends Command
{
    protected $signature = 'feed:publish-scheduled';

    protected $description = 'Publish comments and replies whose scheduled_at has come due, notifies and broadcasts them the same way a fresh comment would be';

    public function __construct(private readonly ScheduledCommentService $scheduled_comments)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $published = $this->scheduled_comments->publishDue();

        $this->components->info("Published {$published} scheduled update(s).");

        return self::SUCCESS;
    }
}
