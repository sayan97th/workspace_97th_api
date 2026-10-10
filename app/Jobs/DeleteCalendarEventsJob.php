<?php

namespace App\Jobs;

use App\Models\ExternalAccount;
use App\Services\ExternalAccounts\ExternalAccountException;
use App\Services\ExternalAccounts\GoogleCalendarClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Deletes the Google Calendar events of an item that was archived or deleted. Carries plain ids,
 * the item's sync rows are already gone when it runs.
 */
class DeleteCalendarEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    /**
     * @param  array<int, array{external_account_id: int, calendar_id: string, event_id: string}>  $events
     */
    public function __construct(public array $events) {}

    public function handle(GoogleCalendarClient $calendar): void
    {
        foreach ($this->events as $event) {
            $account = ExternalAccount::query()->find($event['external_account_id']);
            if (! $account) {
                continue;
            }

            try {
                $calendar->deleteEvent($account, $event['calendar_id'], $event['event_id']);
            } catch (ExternalAccountException $exception) {
                Log::info('A Google Calendar event of a removed item could not be deleted', ['account_id' => $account->id, 'error' => $exception->error_code]);
            }
        }
    }
}
