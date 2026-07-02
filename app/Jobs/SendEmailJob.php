<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 2;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120];

    public function __construct(
        public Mailable $mailable,
        public string $recipientEmail,
    ) {
        $this->onQueue('emails');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('emails'),
        ];
    }

    public function handle(): void
    {
        Mail::to($this->recipientEmail)->send($this->mailable);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Email delivery failed', [
            'recipient' => $this->recipientEmail,
            'mailable' => get_class($this->mailable),
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Dispatch the job with an incremental delay so bulk sends are staggered
     * instead of hammering the mail provider all at once.
     */
    public static function dispatchWithThrottle(Mailable $mailable, string $recipientEmail, int $position = 0): void
    {
        $delay_seconds = $position * (int) config('queue.email_throttle_delay', 3);

        static::dispatch($mailable, $recipientEmail)
            ->delay(now()->addSeconds($delay_seconds));
    }
}
