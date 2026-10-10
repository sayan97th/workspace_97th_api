<?php

namespace App\Jobs;

use App\Models\BoardAutomation;
use App\Models\BoardItem;
use App\Services\ExternalAccounts\CalendarEventSyncer;
use App\Services\ExternalAccounts\ExternalAccountException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Creates or updates the Google Calendar event of one item for one sync automation, in the
 * background like every other action that leaves the app. Two quick edits of the same item never
 * run side by side, otherwise both could create an event.
 */
class SyncCalendarEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    /**
     * @param  array<string, mixed>  $params  the action's params when it ran, so an edited automation does not change a queued sync
     */
    public function __construct(
        public int $automation_id,
        public int $item_id,
        public array $params,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("calendar_sync:{$this->automation_id}:{$this->item_id}"))->releaseAfter(10)->expireAfter(120)];
    }

    public function handle(CalendarEventSyncer $syncer): void
    {
        $automation = BoardAutomation::query()->with(['creator', 'owner'])->find($this->automation_id);
        $item = BoardItem::query()->with('board')->find($this->item_id);
        if (! $automation || ! $item) {
            return;
        }

        try {
            $syncer->sync($automation, $this->params, $item);
        } catch (ExternalAccountException $exception) {
            Log::warning('A Google Calendar event could not be synced', ['automation_id' => $automation->id, 'item_id' => $item->id, 'error' => $exception->error_code]);

            if ($exception->isTokenRevoked() || $exception->error_code === ExternalAccountException::CODE_MISSING_SCOPES) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }
}
