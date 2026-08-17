<?php

namespace App\Console\Commands\Notification;

use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

// php artisan notification:broadcast-test
class NotificationBroadcastTest extends Command
{
    protected $signature = 'notification:broadcast-test
        {--user= : Only send the test notification to the user with this ID}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Broadcast a test notification to user accounts to verify the websocket connection is working';

    public function __construct(private readonly NotificationService $notification_service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $recipient_query = User::query();

        $user_id = $this->option('user');
        if ($user_id !== null) {
            $recipient_query->whereKey($user_id);
        }

        $total = $recipient_query->count();

        if ($total === 0) {
            $this->components->warn('No matching users found. Nothing to send.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->components->confirm(
            "This will broadcast a test notification to {$total} user(s). Continue?"
        )) {
            $this->components->warn('Aborted.');

            return self::SUCCESS;
        }

        $this->components->info("Broadcasting test notification to {$total} user(s)...");

        [$sent_count, $failures] = $this->broadcastToRecipients($recipient_query, $total);

        if ($failures !== []) {
            $this->components->error(sprintf('%d notification(s) failed to send:', count($failures)));
            foreach ($failures as $failure) {
                $this->line("  - {$failure}");
            }
        }

        $this->components->success("Test notification broadcast to {$sent_count} of {$total} user(s).");

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Creates and broadcasts a test notification for every user matched by
     * `$recipient_query`, in chunks so accounts with a large user base don't
     * load every recipient into memory at once.
     *
     * @param  Builder<User>  $recipient_query
     * @return array{0: int, 1: array<int, string>}
     */
    private function broadcastToRecipients(Builder $recipient_query, int $total): array
    {
        $sent_count = 0;
        $failures = [];
        $action_target = sprintf('Broadcast at %s to verify the websocket connection is working', now()->toDayDateTimeString());

        $progress_bar = $this->output->createProgressBar($total);
        $progress_bar->start();

        $recipient_query->chunkById(200, function ($recipients) use (&$sent_count, &$failures, $progress_bar, $action_target) {
            foreach ($recipients as $recipient) {
                try {
                    $this->notification_service->notify(
                        recipient: $recipient,
                        actor: null,
                        type: Notification::TYPE_TEST,
                        board: null,
                        action_label: 'Test notification',
                        action_target: $action_target,
                        link: null,
                    );

                    $sent_count++;
                } catch (Throwable $exception) {
                    $failures[] = "{$recipient->email}: {$exception->getMessage()}";
                }

                $progress_bar->advance();
            }
        });

        $progress_bar->finish();
        $this->newLine(2);

        return [$sent_count, $failures];
    }
}
