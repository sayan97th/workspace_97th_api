<?php

namespace App\Services\Slack;

use App\Enums\SlackNotificationTest;

/**
 * The record of one notification test while it runs: every Slack call it made (its steps),
 * the links it produced and, once it ends, its status and the sentence that explains it.
 */
class SlackNotificationTestRun
{
    public const STATUS_PASSED = 'passed';

    public const STATUS_WARNING = 'warning';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /** @var array<int, array{name: string, status: string, detail: string|null}> */
    private array $steps = [];

    /** @var array<int, array{label: string, url: string}> */
    private array $links = [];

    private string $status = self::STATUS_PASSED;

    private string $detail = '';

    private readonly float $started_at;

    public function __construct(private readonly SlackNotificationTest $test)
    {
        $this->started_at = microtime(true);
    }

    public function addStep(string $name, string $status, ?string $detail = null): void
    {
        $this->steps[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
    }

    public function addLink(string $label, string $url): void
    {
        $this->links[] = ['label' => $label, 'url' => $url];
    }

    public function finish(string $status, string $detail): self
    {
        $this->status = $status;
        $this->detail = $detail;

        return $this;
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string, steps: array<int, array{name: string, status: string, detail: string|null}>, links: array<int, array{label: string, url: string}>, duration_ms: int, ran_at: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->test->value,
            'label' => $this->test->label(),
            'status' => $this->status,
            'detail' => $this->detail,
            'steps' => $this->steps,
            'links' => $this->links,
            'duration_ms' => (int) round((microtime(true) - $this->started_at) * 1000),
            'ran_at' => now()->toIso8601String(),
        ];
    }
}
