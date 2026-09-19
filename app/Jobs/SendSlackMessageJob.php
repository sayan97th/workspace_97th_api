<?php

namespace App\Jobs;

use App\Models\SlackInstallation;
use App\Services\Slack\SlackClient;
use App\Services\Slack\SlackException;
use App\Services\Slack\SlackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one Slack message. Carries the installation id instead of the model so the
 * encrypted bot token is never serialized into the queue payload.
 */
class SendSlackMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120];

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function __construct(
        public int $installation_id,
        public string $channel,
        public string $text,
        public array $blocks = [],
    ) {}

    public function handle(SlackClient $client, SlackService $slack_service): void
    {
        $installation = SlackInstallation::find($this->installation_id);

        if (! $installation) {
            return;
        }

        try {
            $client->postMessage($installation->bot_token, $this->channel, $this->text, $this->blocks);
        } catch (SlackException $exception) {
            if ($exception->isRateLimited()) {
                $this->release($exception->retry_after ?? 30);

                return;
            }

            if ($exception->isTokenInvalid()) {
                $slack_service->handleRevokedInstallation($installation);
            }

            if ($exception->isTokenInvalid() || $exception->isPermanent()) {
                Log::warning('Slack message not delivered', [
                    'channel' => $this->channel,
                    'error' => $exception->error_code,
                ]);

                return;
            }

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Slack delivery failed', [
            'channel' => $this->channel,
            'error' => $exception->getMessage(),
        ]);
    }
}
