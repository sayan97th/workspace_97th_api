<?php

namespace App\Jobs\Automations;

use App\Models\BoardAutomation;
use App\Models\BoardAutomationRunLog;
use App\Support\OutboundWebhookUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Posts one automation's "send a webhook" JSON to its URL, off the request that set it off. The
 * body is signed with the automation's secret when one is set: `X-Automation-Signature` carries
 * `sha256=<hex HMAC of the raw body>`. A failed delivery is written to the automation's run
 * history, a successful one was already recorded as queued.
 */
class SendAutomationWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TIMEOUT_SECONDS = 10;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $automation_id,
        public string $url,
        public array $payload,
        public ?string $secret,
        public ?int $board_item_id,
        public ?string $item_name,
    ) {}

    public function handle(): void
    {
        if ($problem = OutboundWebhookUrl::problem($this->url, resolve: true)) {
            $this->recordFailure($problem);

            return;
        }

        $body = (string) json_encode($this->payload);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => config('app.name').' Automations'];
        if ($this->secret) {
            $headers['X-Automation-Signature'] = 'sha256='.hash_hmac('sha256', $body, $this->secret);
        }

        $response = Http::timeout(self::TIMEOUT_SECONDS)->withHeaders($headers)->withBody($body, 'application/json')->withoutRedirecting()->post($this->url);

        if ($response->serverError()) {
            // Retried by the queue, recorded only once the last try failed, see failed().
            $response->throw();
        }
        if (! $response->successful()) {
            $this->recordFailure("The webhook to {$this->host()} answered {$response->status()}.");
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->recordFailure("The webhook to {$this->host()} could not be delivered.");
    }

    private function host(): string
    {
        return (string) (parse_url($this->url, PHP_URL_HOST) ?: 'the URL');
    }

    private function recordFailure(string $message): void
    {
        $automation = BoardAutomation::find($this->automation_id);
        if (! $automation) {
            return;
        }

        BoardAutomationRunLog::create([
            'automation_id' => $automation->id,
            'board_id' => $automation->board_id,
            'board_view_id' => $automation->board_view_id,
            'board_item_id' => $this->board_item_id,
            'actor_id' => null,
            'automation_name' => $automation->name,
            'item_name' => $this->item_name ? Str::limit($this->item_name, 250, '') : null,
            'trigger_type' => $automation->trigger_type,
            'action_type' => BoardAutomation::ACTION_SEND_WEBHOOK,
            'status' => BoardAutomationRunLog::STATUS_FAILED,
            'message' => $message,
        ]);
    }
}
